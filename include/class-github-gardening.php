<?php

/**
 * Class GitHubGardening
 *
 * @package     github-gardener
 * @copyright   Copyright (c) 2015, Delicious Brains
 * @author      Iain Poulson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */
class GitHubGardening {

	/**
	 * @var GitHubClient
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $owner;

	/**
	 * @var array
	 */
	protected $repos;

	/**
	 * GitHubGardening constructor.
	 */
	public function __construct( $token = null ) {
		$this->client = new GitHubClient();
		$this->client->setAuthType( 'x-oauth-basic' );
		$this->client->setOauthKey( $token );

		$this->owner = 'deliciousbrains';
		$this->repos = array( 'wp-aws', 'wp-migrate-db-pro' );
	}

	/**
	 * Removes redundant labels from closed PRs
	 */
	public function closed_pulls() {
		foreach ( $this->repos as $repo ) {
			$pulls = $this->client->pulls->listPullRequests( $this->owner, $repo, 'closed' );

			foreach ( $pulls as $pull ) {
				$id     = $pull->getNumber();
				$labels = $this->client->issues->labels->listLabelsOnAnIssue( $this->owner, $repo, $id );

				$process_replacement = false;
				$redundant_labels    = array( 'ready for review', 'needs merge' );
				$labels_to_remove    = array();
				foreach ( $labels as $label ) {
					if ( in_array( $label->getName(), $redundant_labels ) ) {
						$labels_to_remove[]  = $label->getName();
						$process_replacement = true;
					}
				}

				if ( ! $process_replacement ) {
					continue;
				}

				// Remove redundant labels
				foreach ( $labels_to_remove as $label ) {
					// TODO
					#$this->client->issues->labels->removeLabelFromAnIssue( $this->owner, $repo, $id, $label );
				}

			}
		}
	}


	/**
	 * Check for open PRs ready for review that are not mergeable
	 */
	public function needs_merge() {
		foreach ( $this->repos as $repo ) {
			$this->_log( $repo );
			$pulls = $this->client->pulls->listPullRequests( $this->owner, $repo, 'open' );

			foreach ( $pulls as $pull ) {
				$id               = $pull->getNumber();
				$labels           = $this->client->issues->labels->listLabelsOnAnIssue( $this->owner, $repo, $id );
				$ready_for_review = false;
				$label_exists     = false;
				foreach ( $labels as $label ) {
					if ( 'ready for review' == $label->getName() ) {
						$ready_for_review = true;
					}

					if ( 'needs merge' == $label->getName() ) {
						$label_exists = true;
					}
				}

				if ( ! $ready_for_review ) {
					continue;
				}

				$pull_request = $this->client->pulls->getSinglePullRequest( $this->owner, $repo, $id );

				if ( $pull_request->isMergeable() ) {
					if ( $label_exists ) {
						// TODO remove label
					}

					continue;
				}

				if ( $label_exists ) {
					// Already got the label
					continue;
				}

				// Add Label
				$label = 'needs merge';
				$this->client->issues->labels->addLabelsToAnIssue( $this->owner, $repo, $id, $label );

				// Add comment
				$comment = '@' . $this->getPullRequestAuthor( $pull_request ) . ' needs develop merged in';
				// @username Needs develop merged in
				$this->client->issues->comments->createComment( $this->owner, $repo, $id, $comment );
			}
		}
	}

	/**
	 * Notify authors of closed PRs that have not had their branch deleted.
	 * This ignores release branches.
	 */
	public function notifyUndeletedBranches() {
		foreach ( $this->repos as $repo ) {
			$pulls = $this->client->pulls->listPullRequests( $this->owner, $repo, 'closed' );

			$branches = $this->client->repos->listBranches( $this->owner, $repo );

			$all_branches = array();
			foreach ( $branches as $branch ) {
				$all_branches[] = $branch->getName();
			}

			foreach ( $pulls as $pull ) {
				$branch = $pull->getHead()->getRef();

				if ( in_array( $branch, $all_branches ) && false === strpos( $branch, 'release' ) ) {
					// Add comment
					$comment = '@' . $this->getPullRequestAuthor( $pull ) . ' branch needs deleting';
					$this->client->issues->comments->createComment( $this->owner, $repo, $pull->getNumber(), $comment );
				}
			}
		}
	}

	/**
	 * Get the author of the pull request
	 *
	 * @param int|GitHubFullPull $pull
	 * @param string             $repo
	 *
	 * @return string
	 */
	private function getPullRequestAuthor( $pull, $repo = '' ) {
		if ( ! is_object( $pull ) ) {
			$pull = $this->client->pulls->getSinglePullRequest( $this->owner, $repo, $pull );
		}

		$user = $pull->getUser();

		// TODO check the author is still a valid team member
		// TODO else use last committer on repo

		return $user->getLogin();
	}
}
