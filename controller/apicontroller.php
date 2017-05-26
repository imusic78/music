<?php

/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @copyright Morris Jobke 2013, 2014
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Artist;
use OCA\Music\Db\Cache;
use OCA\Music\Db\Track;
use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http;
use \OCP\AppFramework\Http\DataDisplayResponse;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\AppFramework\Http\Response;
use \OCP\Files\Folder;
use \OCP\IL10N;
use \OCP\IRequest;
use \OCP\IURLGenerator;

use \OCP\AppFramework\Db\DoesNotExistException;

use \OCA\Music\BusinessLayer\TrackBusinessLayer;
use \OCA\Music\BusinessLayer\ArtistBusinessLayer;
use \OCA\Music\BusinessLayer\AlbumBusinessLayer;
use \OCA\Music\Http\FileResponse;
use \OCA\Music\Utility\Scanner;


class ApiController extends Controller {

	/** @var IL10N */
	private $l10n;
	/** @var TrackBusinessLayer */
	private $trackBusinessLayer;
	/** @var ArtistBusinessLayer */
	private $artistBusinessLayer;
	/** @var AlbumBusinessLayer */
	private $albumBusinessLayer;
	/** @var Cache */
	private $cache;
	/** @var Scanner */
	private $scanner;
	/** @var string */
	private $userId;
	/** @var IURLGenerator */
	private $urlGenerator;
	/** @var Folder */
	private $userFolder;
	/** @var Logger */
	private $logger;

	public function __construct($appname,
								IRequest $request,
								IURLGenerator $urlGenerator,
								TrackBusinessLayer $trackbusinesslayer,
								ArtistBusinessLayer $artistbusinesslayer,
								AlbumBusinessLayer $albumbusinesslayer,
								Cache $cache,
								Scanner $scanner,
								$userId,
								$l10n,
								Folder $userFolder,
								Logger $logger){
		parent::__construct($appname, $request);
		$this->l10n = $l10n;
		$this->trackBusinessLayer = $trackbusinesslayer;
		$this->artistBusinessLayer = $artistbusinesslayer;
		$this->albumBusinessLayer = $albumbusinesslayer;
		$this->cache = $cache;
		$this->scanner = $scanner;
		$this->userId = $userId;
		$this->urlGenerator = $urlGenerator;
		$this->userFolder = $userFolder;
		$this->logger = $logger;
	}

	/**
	 * Extracts the id from an unique slug (id-slug)
	 * @param string $slug the slug
	 * @return string the id
	 */
	protected function getIdFromSlug($slug){
		$split = explode('-', $slug, 2);

		return $split[0];
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function collection() {
		$collectionJson = $this->cache->get($this->userId, 'collection');

		if ($collectionJson == null) {
			$collectionJson = $this->buildCollectionJson();
			$this->cache->add($this->userId, 'collection', $collectionJson);
		}
		return new DataDisplayResponse($collectionJson);
	}

	private function buildCollectionJson() {
		/** @var Artist[] $allArtists */
		$allArtists = $this->artistBusinessLayer->findAll($this->userId);
		$allArtistsByIdAsObj = array();
		$allArtistsByIdAsArr = array();
		foreach ($allArtists as &$artist) {
			$allArtistsByIdAsObj[$artist->getId()] = $artist;
			$allArtistsByIdAsArr[$artist->getId()] = $artist->toCollection($this->l10n);
		}
		
		$allAlbums = $this->albumBusinessLayer->findAll($this->userId);
		$allAlbumsByIdAsObj = array();
		$allAlbumsByIdAsArr = array();
		foreach ($allAlbums as &$album) {
			$allAlbumsByIdAsObj[$album->getId()] = $album;
			$allAlbumsByIdAsArr[$album->getId()] = $album->toCollection($this->urlGenerator, $this->l10n);
		}

		/** @var Track[] $allTracks */
		$allTracks = $this->trackBusinessLayer->findAll($this->userId);

		$artists = array();
		foreach ($allTracks as $track) {
			$albumObj = $allAlbumsByIdAsObj[$track->getAlbumId()];
			$trackArtistObj = $allArtistsByIdAsObj[$track->getArtistId()];
			$track->setAlbum($albumObj);
			$track->setArtist($trackArtistObj);

			$albumArtist = &$allArtistsByIdAsArr[$albumObj->getAlbumArtistId()];
			if (!isset($albumArtist['albums'])) {
				$albumArtist['albums'] = array();
				$artists[] = &$albumArtist;
			}
			$album = &$allAlbumsByIdAsArr[$track->getAlbumId()];
			if (!isset($album['tracks'])) {
				$album['tracks'] = array();
				$albumArtist['albums'][] = &$album;
			}
			try {
				$album['tracks'][] = $track->toCollection($this->urlGenerator, $this->userFolder, $this->l10n);
			} catch (\OCP\Files\NotFoundException $e) {
				//ignore not found
			}
		}
		return json_encode($artists);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function artists() {
		$fulltree = filter_var($this->params('fulltree'), FILTER_VALIDATE_BOOLEAN);
		$includeAlbums = filter_var($this->params('albums'), FILTER_VALIDATE_BOOLEAN);
		/** @var Artist[] $artists */
		$artists = $this->artistBusinessLayer->findAll($this->userId);
		foreach($artists as &$artist) {
			$artist = $artist->toAPI($this->urlGenerator, $this->l10n);
			if($fulltree || $includeAlbums) {
				$artistId = $artist['id'];
				$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $this->userId);
				foreach($albums as &$album) {
					$album = $album->toAPI($this->urlGenerator, $this->l10n);
					if($fulltree) {
						$albumId = $album['id'];
						$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId, $artistId);
						foreach($tracks as &$track) {
							$track = $track->toAPI($this->urlGenerator);
						}
						$album['tracks'] = $tracks;
					}
				}
				$artist['albums'] = $albums;
			}
		}
		return new JSONResponse($artists);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function artist() {
		$fulltree = filter_var($this->params('fulltree'), FILTER_VALIDATE_BOOLEAN);
		$artistId = $this->getIdFromSlug($this->params('artistIdOrSlug'));
		/** @var Artist $artist */
		$artist = $this->artistBusinessLayer->find($artistId, $this->userId);
		$artist = $artist->toAPI($this->urlGenerator, $this->l10n);
		if($fulltree) {
			$artistId = $artist['id'];
			$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $this->userId);
			foreach($albums as &$album) {
				$album = $album->toAPI($this->urlGenerator, $this->l10n);
				$albumId = $album['id'];
				$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId, $artistId);
				foreach($tracks as &$track) {
					$track = $track->toAPI($this->urlGenerator);
				}
				$album['tracks'] = $tracks;
			}
			$artist['albums'] = $albums;
		}
		return new JSONResponse($artist);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function albums() {
		$fulltree = filter_var($this->params('fulltree'), FILTER_VALIDATE_BOOLEAN);
		$albums = $this->albumBusinessLayer->findAll($this->userId);
		foreach($albums as &$album) {
			$artistIds = $album->getArtistIds();
			$album = $album->toAPI($this->urlGenerator, $this->l10n);
			if($fulltree) {
				$albumId = $album['id'];
				$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId);
				foreach($tracks as &$track) {
					$track = $track->toAPI($this->urlGenerator);
				}
				$album['tracks'] = $tracks;
				$artists = $this->artistBusinessLayer->findMultipleById($artistIds, $this->userId);
				foreach($artists as &$artist) {
					$artist = $artist->toAPI($this->urlGenerator, $this->l10n);
				}
				$album['artists'] = $artists;
			}
		}
		return new JSONResponse($albums);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function album() {
		$fulltree = filter_var($this->params('fulltree'), FILTER_VALIDATE_BOOLEAN);
		$albumId = $this->getIdFromSlug($this->params('albumIdOrSlug'));
		$album = $this->albumBusinessLayer->find($albumId, $this->userId);

		$artistIds = $album->getArtistIds();
		$album = $album->toAPI($this->urlGenerator, $this->l10n);
		if($fulltree) {
			$albumId = $album['id'];
			$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId);
			foreach($tracks as &$track) {
				$track = $track->toAPI($this->urlGenerator);
			}
			$album['tracks'] = $tracks;
			$artists = $this->artistBusinessLayer->findMultipleById($artistIds, $this->userId);
			foreach($artists as &$artist) {
				$artist = $artist->toAPI($this->urlGenerator, $this->l10n);
			}
			$album['artists'] = $artists;
		}

		return new JSONResponse($album);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function tracks() {
		$fulltree = filter_var($this->params('fulltree'), FILTER_VALIDATE_BOOLEAN);
		if($artistId = $this->params('artist')) {
			$tracks = $this->trackBusinessLayer->findAllByArtist($artistId, $this->userId);
		} elseif($albumId = $this->params('album')) {
			$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->userId);
		} else {
			$tracks = $this->trackBusinessLayer->findAll($this->userId);
		}
		foreach($tracks as &$track) {
			$artistId = $track->getArtistId();
			$albumId = $track->getAlbumId();
			$track = $track->toAPI($this->urlGenerator);
			if($fulltree) {
				/** @var Artist $artist */
				$artist = $this->artistBusinessLayer->find($artistId, $this->userId);
				$track['artist'] = $artist->toAPI($this->urlGenerator, $this->l10n);
				$album = $this->albumBusinessLayer->find($albumId, $this->userId);
				$track['album'] = $album->toAPI($this->urlGenerator, $this->l10n);
			}
		}
		return new JSONResponse($tracks);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function track() {
		$trackId = $this->getIdFromSlug($this->params('trackIdOrSlug'));
		/** @var Track $track */
		$track = $this->trackBusinessLayer->find($trackId, $this->userId);
		return new JSONResponse($track->toAPI($this->urlGenerator));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function trackByFileId() {
		$fileId = $this->params('fileId');
		$track = $this->trackBusinessLayer->findByFileId($fileId, $this->userId);
		$track->setAlbum($this->albumBusinessLayer->find($track->getAlbumId(), $this->userId));
		$track->setArtist($this->artistBusinessLayer->find($track->getArtistId(), $this->userId));
		return new JSONResponse($track->toCollection($this->urlGenerator, $this->userFolder, $this->l10n));
	}

	/**
	 * @NoAdminRequired
	 */
	public function getScanState() {
		return new JSONResponse([
			'unscannedFiles' => $this->scanner->getUnscannedMusicFileIds($this->userId, $this->userFolder),
			'scannedCount' => count($this->scanner->getScannedFiles($this->userId))
		]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function scan() {
		// extract the parameters
		$fileIds = array_map('intval', explode(',', $this->params('files')));
		$finalize = filter_var($this->params('finalize'), FILTER_VALIDATE_BOOLEAN);

		$filesScanned = $this->scanner->scanFiles($this->userId, $this->userFolder, $fileIds);

		$coversUpdated = false;
		if ($finalize) {
			$coversUpdated = $this->scanner->findCovers();
			$totalCount = count($this->scanner->getScannedFiles($this->userId));
			$this->logger->log("Scanning finished, user $this->userId has $totalCount scanned tracks in total", 'info');
		}

		return new JSONResponse([
			'filesScanned' => $filesScanned,
			'coversUpdated' => $coversUpdated
		]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function download() {
		// we no longer need the session to be kept open
		session_write_close();

		$fileId = $this->params('fileId');

		try {
			$track = $this->trackBusinessLayer->findByFileId($fileId, $this->userId);
		} catch(DoesNotExistException $e) {
			$r = new Response();
			$r->setStatus(Http::STATUS_NOT_FOUND);
			return $r;
		}

		$nodes = $this->userFolder->getById($track->getFileId());
		if(count($nodes) > 0 ) {
			// get the first valid node
			$node = $nodes[0];

			$mime = $node->getMimeType();
			$content = $node->getContent();
			return new FileResponse(array('mimetype' => $mime, 'content' => $content));
		}

		$r = new Response();
		$r->setStatus(Http::STATUS_NOT_FOUND);
		return $r;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function cover() {
		// we no longer need the session to be kept open
		session_write_close();

		$albumId = $this->getIdFromSlug($this->params('albumIdOrSlug'));
		$album = $this->albumBusinessLayer->find($albumId, $this->userId);

		$nodes = $this->userFolder->getById($album->getCoverFileId());
		if(count($nodes) > 0 ) {
			// get the first valid node
			$node = $nodes[0];
			$mime = $node->getMimeType();

			if (0 === strpos($mime, 'audio')) { // embedded cover image
				$cover = $this->scanner->parseEmbeddedCoverArt($node);

				if($cover != null) {
					return new FileResponse(array(
							'mimetype' => $cover["image_mime"],
							'content' => $cover["data"]
					));
				}
			}
			else { // separate image file
				$content = $node->getContent();
				return new FileResponse(array('mimetype' => $mime, 'content' => $content));
			}
		}

		$r = new Response();
		$r->setStatus(Http::STATUS_NOT_FOUND);
		return $r;
	}
}
