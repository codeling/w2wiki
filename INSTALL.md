# Installation

1. Create a directory for W2 somewhere in your web server's document
   root path. It doesn't matter where. W2 requires PHP 8.1 or higher.
   
2. Upload the files from this repository to this directory.

3. Make sure that the "images" and "pages" directories are writable by your
   web server process.
   
4. You may or may not need to edit config.php. When you're ready, look in
   there for many additional configuration options
   (see below for more information).

You should now be ready to access your W2 installation.


# Configuration

The file config.php contains many options for you to customize your W2 setup.

A few examples:

The following line in config.php may be changed if you do not want the 
default page to be named 'Home':
```
define('DEFAULT_PAGE', 'Home');
```

The following line in config.php may be changed if you'd like to use a
different CSS stylesheet:
```
define('CSS_FILE', 'index.css');
```

The size of the edit textarea is controlled by:
```
define('EDIT_ROWS', 18);
```

## Security

**Note:** In it's current state, W2 wiki is not intended to be run as a public
wiki in places where malicious actors can access it. It is neither extensively
tested, nor is it security-hardened, and might therefore compromise the
security of your server. We therefore recommend to only make it available in
a small local network where each user is known, or behind VPN, or at least
secured via HTTPS and behind a Basic Authentication configured in your
webserver. The latter even allows for multiple different users, i.e. a more
fine-grained access control than what W2 wiki itself currently provides. The
features described below might be removed in the near future in this fork!

W2 has the ability to prompt for a password before allowing access to the
site.  Two lines in config.php control this:
```
define('REQUIRE\_PASSWORD', false);
define('W2\_PASSWORD', 'secret');
```

Set `REQUIRE_PASSWORD` to true and set `W2_PASSWORD` to the password you'd like
to use.
**Note:** This is a very rudimentary way of authorizing access. A slightly more
secure variant is to leave `W2_PASSWORD` empty, and use the `W2_PASSWORD_HASH`
setting instead.

## Git Integration

**Note:** The following assumes you are running W2 wiki on a Linux server, and
that you have shell access on your server. If you do not have such access,
this integration will not work.

**Prerequisite:** git needs to be installed on the server, and needs to be
callable via php's `exec` function.
To install git for example on an Ubuntu server, you would execute
`$ sudo apt install git` on a shell

The following assumes that the webserver is running under the `www-data`
user (Ubuntu). If you are running your server under a different distribution,
please consult its documentation or community regarding the proper username.

To enable changes in pages to be committed to a local git repository, you need to:

- Take note of the folder where the pages are stored (`PAGES_PATH` in config.php).
- Navigate to this `PAGES_PATH` folder.
- Create a git repository: `$ sudo -u www-data git init`.
- Add all files there: `$ sudo -u www-data git add -A`.
- Make sure username/email are setup (add `--global` after `config` in below
  commands to make these settings available for all git repositories on this
  machine):
    - email: `$ sudo -u www-data git config user.email "w2.wiki@example.com"`
	- user: `$ sudo -u www-data git config user.name "W2 git user"`
- Do an initial commit: `$ sudo -u www-data git commit -m "Initial commit`.
- Set `GIT_COMMIT_ENABLED` to `true` in `config.php`.

To also enable pushing changes to a remote repository, you need to (in addition
to the steps above):

- Create a remote repository somewhere.
- Add this repository as remote in the repository in your `PAGES_PATH`:
  `$ sudo -u www-data git remote add origin [YOUR_REMOTE_REPO_URL]`
  (replace `[YOUR_REMOTE_REPO_URL]` with the publicly accessible URL of the remote).
- Make sure a remote tracking branch is set; you can achieve this in two ways;
    Both set the branch `main` on the remote `origin` as tracking branch;
	The first option will create the branch if it doesn't exist, and will
	upload all local content so far to that branch:
    - Either push to the remote tracking branch like this:
      `$ sudo -u www-data git push -u origin main`
    - or set a remote branch as tracking branch:
      `$ sudo -u www-data git branch -u origin main`
- Set `GIT_PUSH_ENABLED` to `true` in `config.php`.

