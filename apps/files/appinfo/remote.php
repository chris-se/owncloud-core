<?php

/**
 * ownCloud
 *
 * @author Frank Karlitschek
 * @author Jakob Sack
 * @copyright 2012 Frank Karlitschek frank@owncloud.org
 * @copyright 2011 Jakob Sack kde@jakobsack.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

// Backends
$authBackend = new \OC\Connector\Sabre\Auth();
$lockBackend = new OC_Connector_Sabre_Locks();
//$requestBackend = new OC_Connector_Sabre_Request();

// Fire up server
$objectTree = new \OC\Connector\Sabre\ObjectTree();
$server = new \OC\Connector\Sabre\Server($objectTree);
// FIXME use another approach ?
// $server->httpRequest = $requestBackend;
$server->setBaseUri($baseuri);

// Load plugins
$defaults = new OC_Defaults();
$server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend, $defaults->getName()));
$server->addPlugin(new \Sabre\DAV\Locks\Plugin($lockBackend));
$server->addPlugin(new \Sabre\DAV\Browser\Plugin(false, false)); // Show something in the Browser, but no upload
$server->addPlugin(new \OC\Connector\Sabre\FilesPlugin());
$server->addPlugin(new OC_Connector_Sabre_MaintenancePlugin());
$server->addPlugin(new OC_Connector_Sabre_ExceptionLoggerPlugin('webdav'));

// wait with registering these until auth is handled and the filesystem is setup
$server->on('beforeMethod', function () use ($server, $objectTree) {
	$view = \OC\Files\Filesystem::getView();
	$rootInfo = $view->getFileInfo('');

	// Create ownCloud Dir
	$mountManager = \OC\Files\Filesystem::getMountManager();
	$rootDir = new \OC\Connector\Sabre\Directory($view, $rootInfo);
	$objectTree->init($rootDir, $view, $mountManager);

	$server->addPlugin(new \OC\Connector\Sabre\TagsPlugin($objectTree, \OC::$server->getTagManager()));
	$server->addPlugin(new \OC\Connector\Sabre\QuotaPlugin($view));

	// custom properties plugin must be the last one
	$server->addPlugin(
		new \Sabre\DAV\PropertyStorage\Plugin(
			new \OC\Connector\Sabre\CustomPropertiesBackend(
				$objectTree,
				\OC::$server->getDatabaseConnection(),
				\OC::$server->getUserSession()->getUser()
			)
		)
	);
}, 30); // priority 30: after auth (10) and acl(20), before lock(50) and handling the request

// And off we go!
$server->exec();
