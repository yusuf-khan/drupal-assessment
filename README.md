# Drupal Assessment

This is a Composer-managed Drupal 11.4 project with the web document root in
`web/`. Drupal core and Composer dependencies are installed locally and are
recreated from `composer.lock`.

## Requirements

- Git
- Docker
- [DDEV](https://ddev.com/get-started/)

The included DDEV configuration uses PHP 8.4 and MariaDB 11.8.

## Install locally

Clone the repository, then run these commands from its root:

```sh
git clone https://github.com/yusuf-khan/REPOSITORY_NAME.git
cd REPOSITORY_NAME
ddev start
ddev composer install
ddev launch
```

Replace `REPOSITORY_NAME` with the repository's actual GitHub name. The first
launch opens Drupal's installer. Choose the Standard installation profile and
create a local administrator account. DDEV provides the local database
connection; do not add local database credentials to Git.

To confirm the site is available:

```sh
ddev describe
ddev drush status
```

The DDEV project name is `drupal-assessment`, so its default URL is
`https://drupal-assessment.ddev.site`.

## Repository contents

The repository contains application source and Composer manifests only.
Composer dependencies, Drupal core, local settings, generated assets, uploaded
files, and local database snapshots are excluded from Git. A database or
uploaded-content export is not included; a fresh installation starts with an
empty site.

Assessment-specific functionality, configuration, and test instructions should
be documented here once they are implemented from the assignment brief.
