<?php
/*
        Modified to work with 1.21 and CloudFront.
  	Owen Borseth - owen at borseth dot us
*/

 /**
 * A repository for files accessible via the Amazon S3 service, treated as local filesystem.
 * Does not support database access or registration.
 *
 * Based on LocalFile.php, LocalRepo.php, FSRepo.php, File.php and OldLocalFile.php (ver 1.16-alpha, r69121)
 *
 *** Installation instructions ***
Need to add to the end of LocalSettings.php:

// AWS access info
// s3 filesystem repo
// Location of files in S3:
//	http://s3.amazonaws.com/$wgUploadS3Bucket/$wgUploadDirectory/.....
$wgUploadS3Bucket = '---change me----'; // ******* Your S3 bucket to be used *******
$wgUploadDirectory = 'wiki-images'; // prefix to uploaded files
$wgUploadS3SSL = false; // true if SSL should be used
$wgPublicS3 = true; // true if public, false if authentication should be used
$wgS3BaseUrl = "http".($wgUploadS3SSL?"s":"")."://s3.amazonaws.com/$wgUploadS3Bucket";
$wgUploadBaseUrl = "$wgS3BaseUrl/$wgUploadDirectory";
$wgLocalFileRepo = array(
	'AWS_ACCESS_KEY' => '---change me----', // ********** Your S3 access key *************
	'AWS_SECRET_KEY' => '---change me----', // ********** Your S3 secret key *************
	'class' => 'LocalS3Repo',
	'name' => 's3',
	'directory' => $wgUploadDirectory,
	'url' => $wgUploadBaseUrl ? $wgUploadBaseUrl . $wgUploadPath : $wgUploadPath,
	'urlbase' => $wgS3BaseUrl ? $wgS3BaseUrl : "",
	'hashLevels' => $wgHashedUploadDirectory ? 2 : 0,
	'thumbScriptUrl' => $wgThumbnailScriptPath,
	'transformVia404' => !$wgGenerateThumbnailOnParse,
	'initialCapital' => $wgCapitalLinks,
	'deletedDir' => $wgUploadDirectory.'/deleted',
	'deletedHashLevels' => $wgFileStore['deleted']['hash'],
	'AWS_S3_BUCKET' => $wgUploadS3Bucket,
	'AWS_S3_PUBLIC' => $wgPublicS3,
	'AWS_S3_SSL' => $wgUploadS3SSL
);
require_once("$IP/extensions/LocalS3Repo2/LocalS3Repo.php");
// s3 filesystem repo - end
 ***
 * @ingroup FileRepo
 */
 
if (!class_exists('S3')) require_once 'S3.php';
require_once("$IP/extensions/LocalS3Repo2/FSs3Repo.php");
require_once("$IP/extensions/LocalS3Repo2/LocalS3File.php");
require_once("$IP/extensions/LocalS3Repo2/OldLocalS3File.php");

 // Instantiate the class
$s3 = new S3();

class LocalS3Repo extends FSs3Repo {
	var $fileFactory = array( 'LocalS3File', 'newFromTitle' );
	var $oldFileFactory = array( 'OldLocalS3File', 'newFromTitle' );
	var $fileFromRowFactory = array( 'LocalS3File', 'newFromRow' );
	var $oldFileFromRowFactory = array( 'OldLocalS3File', 'newFromRow' );

	function newFileFromRow( $row ) {
		if ( isset( $row->img_name ) ) {
			return call_user_func( $this->fileFromRowFactory, $row, $this );
		} elseif ( isset( $row->oi_name ) ) {
			return call_user_func( $this->oldFileFromRowFactory, $row, $this );
		} else {
			throw new MWException( __METHOD__.': invalid row' );
		}
	}

	function newFromArchiveName( $title, $archiveName ) {
		return OldLocalS3File::newFromArchiveName( $title, $this, $archiveName );
	}

	/**
	 * Delete files in the deleted directory if they are not referenced in the
	 * filearchive table. This needs to be done in the repo because it needs to
	 * interleave database locks with file operations, which is potentially a
	 * remote operation.
	 * @return FileRepoStatus
	 */
	function cleanupDeletedBatch(array $storageKeys) {
		$root = $this->getZonePath( 'deleted' );
		$dbw = $this->getMasterDB();
		$status = $this->newGood();
		$storageKeys = array_unique($storageKeys);
		foreach ( $storageKeys as $key ) {
			$hashPath = $this->getDeletedHashPath( $key );
			$path = "$root/$hashPath$key";
			$dbw->begin();
			$inuse = $dbw->selectField( 'filearchive', '1',
				array( 'fa_storage_group' => 'deleted', 'fa_storage_key' => $key ),
				__METHOD__, array( 'FOR UPDATE' ) );
			if( !$inuse ) {
				$sha1 = substr( $key, 0, strcspn( $key, '.' ) );
				$ext = substr( $key, strcspn($key,'.') + 1 );
				$ext = File::normalizeExtension($ext);
				$inuse = $dbw->selectField( 'oldimage', '1',
					array( 'oi_sha1' => $sha1,
						'oi_archive_name ' . $dbw->buildLike( $dbw->anyString(), ".$ext" ),
						'oi_deleted & ' . File::DELETED_FILE => File::DELETED_FILE ),
					__METHOD__, array( 'FOR UPDATE' ) );
			}
			if ( !$inuse ) {
				wfDebug( __METHOD__ . ": deleting $key\n" );
				if ( !@unlink( $path ) ) {
					$status->error( 'undelete-cleanup-error', $path );
					$status->failCount++;
				}
			} else {
				wfDebug( __METHOD__ . ": $key still in use\n" );
				$status->successCount++;
			}
			$dbw->commit();
		}
		return $status;
	}
	
	/**
	 * Checks if there is a redirect named as $title
	 *
	 * @param $title Title of file
	 */
	function checkRedirect(Title $title) {
		global $wgMemc;

		if( is_string( $title ) ) {
			$title = Title::newFromTitle( $title );
		}
		if( $title instanceof Title && $title->getNamespace() == NS_MEDIA ) {
			$title = Title::makeTitle( NS_FILE, $title->getText() );
		}

		$memcKey = $this->getSharedCacheKey( 'image_redirect', md5( $title->getDBkey() ) );
		if ( $memcKey === false ) {
			$memcKey = $this->getLocalCacheKey( 'image_redirect', md5( $title->getDBkey() ) );
			$expiry = 300; // no invalidation, 5 minutes
		} else {
			$expiry = 86400; // has invalidation, 1 day
		}
		$cachedValue = $wgMemc->get( $memcKey );
		if ( $cachedValue === ' '  || $cachedValue === '' ) {
			// Does not exist
			return false;
		} elseif ( strval( $cachedValue ) !== '' ) {
			return Title::newFromText( $cachedValue, NS_FILE );
		} // else $cachedValue is false or null: cache miss

		$id = $this->getArticleID( $title );
		if( !$id ) {
			$wgMemc->set( $memcKey, " ", $expiry );
			return false;
		}
		$dbr = $this->getSlaveDB();
		$row = $dbr->selectRow(
			'redirect',
			array( 'rd_title', 'rd_namespace' ),
			array( 'rd_from' => $id ),
			__METHOD__
		);

		if( $row && $row->rd_namespace == NS_FILE ) {
			$targetTitle = Title::makeTitle( $row->rd_namespace, $row->rd_title );
			$wgMemc->set( $memcKey, $targetTitle->getDBkey(), $expiry );
			return $targetTitle;
		} else {
			$wgMemc->set( $memcKey, '', $expiry );
			return false;
		}
	}


	/**
	 * Function link Title::getArticleID().
	 * We can't say Title object, what database it should use, so we duplicate that function here.
	 */
	protected function getArticleID( $title ) {
		if( !$title instanceof Title ) {
			return 0;
		}
		$dbr = $this->getSlaveDB();
		$id = $dbr->selectField(
			'page',	// Table
			'page_id',	//Field
			array(	//Conditions
				'page_namespace' => $title->getNamespace(),
				'page_title' => $title->getDBkey(),
			),
			__METHOD__	//Function name
		);
		return $id;
	}

	/**
	 * Get an array or iterator of file objects for files that have a given 
	 * SHA-1 content hash.
	 */
	function findBySha1( $hash ) {
		$dbr = $this->getSlaveDB();
		$res = $dbr->select(
			'image',
			LocalS3File::selectFields(),
			array( 'img_sha1' => $hash )
		);
		
		$result = array();
		while ( $row = $res->fetchObject() )
			$result[] = $this->newFileFromRow( $row );
		$res->free();
		return $result;
	}

	/**
	 * Get a connection to the slave DB
	 */
	function getSlaveDB() {
		return wfGetDB( DB_SLAVE );
	}

	/**
	 * Get a connection to the master DB
	 */
	function getMasterDB() {
		return wfGetDB( DB_MASTER );
	}

	/**
	 * Get a key on the primary cache for this repository.
	 * Returns false if the repository's cache is not accessible at this site. 
	 * The parameters are the parts of the key, as for wfMemcKey().
	 */
	function getSharedCacheKey( /*...*/ ) {
		$args = func_get_args();
		return call_user_func_array( 'wfMemcKey', $args );
	}

	/**
	 * Invalidates image redirect cache related to that image
	 *
	 * @param $title Title of page
	 */
	function invalidateImageRedirect(Title $title) {
		global $wgMemc;
		$memcKey = $this->getSharedCacheKey( 'image_redirect', md5( $title->getDBkey() ) );
		if ( $memcKey ) {
			$wgMemc->delete( $memcKey );
		}
	}
}

