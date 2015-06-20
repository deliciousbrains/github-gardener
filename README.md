# GitHub Gardener

This is a script we use at Delicious Brains to automatically clean up issues and PRs.

The script is meant to be run on a cron job to keep things clean.

Currently the script does:

- Check for open PRs ready for review that are not mergeable, and adds a label and comment to the author.
- Checks for un-deleted branches on closed PRs, excluding branches with 'release' in their name.

Coming soon:

- Remove redundant labels from closed PRs

## Install

- Clone the repo
- Run `composer install`
- Rename the `.env-sample.php` to `.env.php`
- Add your GitHub [personal access token](https://github.com/settings/tokens) to `.env.php` 
- Set up a cron job to hit `index.php` on a desired schedule