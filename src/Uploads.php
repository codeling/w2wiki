<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * W2
 *
 * Storing uploaded files.
 */

namespace W2;

if (!defined('W2APP')){ die('No direct access.'); }

/**
 * Checks an uploaded file (type, extension, SVG content), shrinks, rotates and converts images if wanted, stores
 * the file in the uploads folder and commits it. Gets what it needs as parameters (nothing is read from the
 * request) and returns what happened as a {@see ChangeResult}.
 */
final class Uploads
{
	/**
	 * @param array $file		the entry of $_FILES: name, tmp_name, error
	 * @param string $newName	name chosen by the user instead of the name of the file, "" for none
	 * @param bool $overwrite	whether an existing file may be replaced
	 * @param bool $resize		whether images which are too large are shrunk
	 * @param int $maxSize		the most pixels of the longer side of a shrunk image
	 */
	public function store(array $file, string $newName, bool $overwrite, bool $resize, int $maxSize): ChangeResult
	{
		$uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ( $uploadError === UPLOAD_ERR_NO_FILE )
		{
			return new ChangeResult(false, '', (new Note())->text(translate('Upload error').": ".translate('No file selected!')));
		}
		if ( $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE )
		{
			return new ChangeResult(false, '', (new Note())->format(
				translate('Upload error: the file is larger than the allowed %s (upload_max_filesize).'), array(ini_get('upload_max_filesize'))));
		}
		$tmpName = $file['tmp_name'];
		$dstName = uploadTargetName($file['name'], $newName);
		// $fileType = $_FILES['userfile']['type']; // as noted in https://www.php.net/manual/en/reserved.variables.files.php, the type specified here is client-specified and thus shouldn't be trusted
		$fileType = mime_content_type($tmpName);
		$fileExt = getFileExt($dstName);
		$note = new Note();
		$typeAllowed = in_array($fileType, validUploadTypes(), true);
		$svgData = null;
		if ( $fileExt === 'svg' )
		{
			// the detected type of SVG files varies (depending on the system's magic database);
			// what counts is that the sanitizer accepts the file as SVG
			$typeAllowed = in_array($fileType, array('image/svg+xml', 'text/xml', 'application/xml', 'text/plain'), true);
			if ( $typeAllowed )
			{
				$svgData = sanitizeUploadedSvg($tmpName);
				$typeAllowed = ($svgData !== false);
			}
		}
		// (and the content must be of the type belonging to the extension, which decides how it is processed)
		if ($typeAllowed && hasValidUploadExt($dstName) && ($fileExt === 'svg' || uploadTypeMatchesExt($fileType, $fileExt)))
		{
			$path = PAGES_PATH . "/". UPLOAD_FOLDER . "/$dstName";
			$doResize = $resize;
			$doConvert = in_array($fileExt, explode(',', IMAGE_EXTS_TO_CONVERT));
			// never let ImageMagick parse SVG files (external references, delegates)
			$doProcess = in_array($fileExt, \ImageExtensions, true) && $fileExt !== 'svg' && ($doConvert || $doResize);
			// (without the imagick extension, images are stored as they are, unless they have to be converted)
			$resizeSkipped = false;
			$conversionImpossible = false;
			if ($doProcess && !imageProcessingAvailable())
			{
				$conversionImpossible = $doConvert;
				$resizeSkipped = !$doConvert;
				$doProcess = false;
				$doResize = false;
			}
			$pathNoExt = substr($path, 0, strlen($path)-strlen($fileExt)-1);
			$finalPath = ($doProcess && $doConvert) ? ($pathNoExt.".".CONVERT_FORMAT) : $path;
			if ($doProcess)
			{
				$path = $pathNoExt . "-tmp-process." . $fileExt;
			}
			if ( $conversionImpossible )
			{
				w2Log("can't convert the upload $dstName: image processing is not available");
				$note->text(translate('Upload error').": ")
					->format(translate('%s needs to be converted to %s, but image processing (the PHP extension imagick) is not available on this server.'), array($dstName, CONVERT_FORMAT));
			}
			else if ( file_exists($finalPath) && !$overwrite )
			{
				$note->text(translate('Upload error').": ")
					->format(translate('%s already exists!'), array(basename($finalPath)));
			}
			else if ( ($svgData !== null) ? (file_put_contents($path, $svgData) !== false) : (move_uploaded_file($tmpName, $path) === true) )
			{
				$commitMsg = "File '$dstName' uploaded!";
				$note->format(translate("File '%s' uploaded!"), array($dstName))->text(" ");
				$processFailed = false;
				if ($resizeSkipped)
				{
					$note->text(translate('The image was not shrunk or rotated: image processing (the PHP extension imagick) is not available on this server.')." ");
				}
				if ($doProcess)
				{
					try
					{
						// the format is given explicitly, ImageMagick must not guess it from the content
						$source = imageMagickFormatPrefix($fileExt) . $path;
						$probe = new \Imagick();
						$probe->pingImage($source);
						$pixels = $probe->getImageWidth() * $probe->getImageHeight();
						$probe->clear();
						if ($pixels > MAX_IMAGE_PIXELS)
						{
							throw new \ImagickException('image has too many pixels');
						}
						$img = new \Imagick($source);
						if ($doResize)
						{
							$size = array($img->getImageWidth(), $img->getImageHeight());
							$maxsize = max(20, min(8192, $maxSize));
							$doResize = ($size[0] > $maxsize || $size[1] > $maxsize);
						}
						if ($doResize)
						{
							$newSize = array(0, 0);
							$idx0 = ($size[0] > $size[1]) ? 0 : 1;
							$idx1 = ($idx0 == 0) ? 1 : 0;
							$newSize[$idx0] = $maxsize;
							$newSize[$idx1] = (int)round($size[$idx1] * $maxsize / $size[$idx0]);
							try
							{
								$img->resizeImage($newSize[0], $newSize[1], \Imagick::FILTER_LANCZOS, 1);
								$note->format(translate('Original size was %s, resized to %s.'), array("$size[0]x$size[1]", "$newSize[0]x$newSize[1]"))->text(" ");
							}
							catch (\ImagickException $e)
							{
								$note->text(translate('Resizing file failed!')." ");
							}
						}
						$ori = $img->getImageOrientation();
						if($ori)
						{
							switch($ori)
							{
							case \Imagick::ORIENTATION_RIGHTTOP:
								$note->format(translate('Image rotated by %s°.'), array('+90'))->text(" ");
								$img->rotateImage('#000',90);
								break;
							case \Imagick::ORIENTATION_BOTTOMRIGHT:
								$note->format(translate('Image rotated by %s°.'), array('180'))->text(" ");
								$img->rotateImage('#000',180);
								break;
							case \Imagick::ORIENTATION_LEFTBOTTOM:
								$note->format(translate('Image rotated by %s°.'), array('-90'))->text(" ");
								$img->rotateImage('#000',-90);
								break;
	//						default:
	//							$note->text("Unknown EXIF orientation specification: ".$ori.". ");
	//							break;
							}
							$img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
						}
						if ($doConvert)
						{
							$dstName = substr($dstName, 0, strlen($dstName)-strlen($fileExt)).CONVERT_FORMAT;
							$img->setImageFormat(CONVERT_FORMAT);
							$note->format(translate('Converted to format %s.'), array(CONVERT_FORMAT))->text(" ");
						}
						$img->writeImage($finalPath);
						unlink($path);
						$img->clear();
					}
					catch (\ImagickException $e)
					{
						// e.g. a corrupt image with a valid header: don't leave the half-processed upload behind
						w2Log('processing the upload failed: '.$e->getMessage());
						if (file_exists($path))
						{
							unlink($path);
						}
						$processFailed = true;
						$note = (new Note())->text(translate('Upload error').": ")
							->format(translate('%s could not be processed (is it a valid image?)'), array($dstName));
					}
				}

				if (!$processFailed)
				{
					gitChangeHandler($commitMsg, $note);
					$note->format(translate('Use %s to refer to it!'), array(Note::block(imageLinkText($dstName))));
				}
			}
			else
			{
				$error_code = $file['error'];
				if ( $error_code === 0 )
				{
					// Likely a permissions issue
					w2Log("can't write upload to $path");
					$note->text(translate('Upload error') .": ".translate("Can't write to the uploads folder"))->br(2)
						->text(translate('Check that your permissions are set correctly.'));
				}
				else
				{
					// Give generic error message
					$note->text(translate('Upload error').", error #".$error_code)->br(2)
						->format(translate('Please see %s for more information.'), array(Note::link(translate('here'), 'https://www.php.net/manual/en/features.file-upload.errors.php')))->br(2)
						->format(translate('If you see this message, please %s'), array(Note::link(translate('file a bug to improve w2wiki'), 'https://github.com/codeling/w2wiki/issues')));
				}
			}
		}
		else
		{
			$note->text(translate('Upload error: invalid file type'));
			w2Log("Upload error: file name = $dstName, invalid file type $fileType");
		}
		return new ChangeResult(true, '', $note);
	}
}
