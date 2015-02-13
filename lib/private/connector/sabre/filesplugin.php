<?php
namespace OC\Connector\Sabre;

/**
 * ownCloud
 *
 * @author Thomas Müller
 * @copyright 2013 Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL3
 */

use \Sabre\DAV\PropFind;
use \Sabre\DAV\PropPatch;

class FilesPlugin extends \Sabre\DAV\ServerPlugin
{

	// namespace
	const NS_OWNCLOUD = 'http://owncloud.org/ns';
	const FILEID_PROPERTYNAME = '{http://owncloud.org/ns}id';
	const PERMISSIONS_PROPERTYNAME = '{http://owncloud.org/ns}permissions';
	const DOWNLOADURL_PROPERTYNAME = '{http://owncloud.org/ns}downloadURL';
	const SIZE_PROPERTYNAME = '{http://owncloud.org/ns}size';

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param \Sabre\DAV\Server $server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server) {

		$server->xmlNamespaces[self::NS_OWNCLOUD] = 'oc';
		$server->protectedProperties[] = FILEID_PROPERTYNAME;
		$server->protectedProperties[] = PERMISSIONS_PROPERTYNAME;
		$server->protectedProperties[] = SIZE_PROPERTYNAME;
		$server->protectedProperties[] = DOWNLOADURL_PROPERTYNAME;

		$this->server = $server;
		$this->server->on('propFind', array($this, 'handleGetProperties'));
		$this->server->on('afterBind', array($this, 'sendFileIdHeader'));
		$this->server->on('afterWriteContent', array($this, 'sendFileIdHeader'));
	}

	/**
	 * Adds all ownCloud-specific properties
	 *
	 * @param PropFind $propFind
	 * @param \Sabre\DAV\INode $node
	 * @return void
	 */
	public function handleGetProperties(PropFind $propFind, \Sabre\DAV\INode $node) {

		if ($node instanceof \OC\Connector\Sabre\Node) {

			$propFind->handle(self::FILEID_PROPERTYNAME, function() use ($node) {
				return $node->getFileId();
			});

			/** @var $node \OC\Connector\Sabre\Node */
			$propFind->handle(self::PERMISSIONS_PROPERTYNAME, function() use ($node) {
				return $node->getDavPermissions();
			});
		}

		if ($node instanceof \OC\Connector\Sabre\File) {
			$propFind->handle(self::DOWNLOADURL_PROPERTYNAME, function() use ($node) {
				/** @var $node \OC\Connector\Sabre\File */
				$directDownloadUrl = $node->getDirectDownload();
				if (isset($directDownloadUrl['url'])) {
					return $directDownloadUrl['url'];
				}
				return false;
			});
		}

		if ($node instanceof \OC\Connector\Sabre\Directory) {
			$propFind->handle(self::SIZE_PROPERTYNAME, function() use ($node) {
				return $node->getSize();
			});
		}
	}

	/**
	 * @param string $filePath
	 * @param \Sabre\DAV\INode $node
	 * @throws \Sabre\DAV\Exception\BadRequest
	 */
	public function sendFileIdHeader($filePath, \Sabre\DAV\INode $node = null) {
		// chunked upload handling
		if (isset($_SERVER['HTTP_OC_CHUNKED'])) {
			list($path, $name) = \Sabre\HTTP\URLUtil::splitPath($filePath);
			$info = \OC_FileChunking::decodeName($name);
			if (!empty($info)) {
				$filePath = $path . '/' . $info['name'];
			}
		}

		// we get the node for the given $filePath here because in case of afterCreateFile $node is the parent folder
		if (!$this->server->tree->nodeExists($filePath)) {
			return;
		}
		$node = $this->server->tree->getNodeForPath($filePath);
		if ($node instanceof \OC\Connector\Sabre\Node) {
			$fileId = $node->getFileId();
			if (!is_null($fileId)) {
				$this->server->httpResponse->setHeader('OC-FileId', $fileId);
			}
		}
	}

}
