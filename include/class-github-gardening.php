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
				$user    = $pull_request->getUser();
				$comment = '@' . $user->getLogin() . ' needs develop merged in';
				// @username Needs develop merged in
				$this->client->issues->comments->createComment( $this->owner, $repo, $id, $comment );
			}
		}
	}
}
