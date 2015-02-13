<?php
namespace OC\Connector\Sabre;

/**
 * ownCloud
 *
 * @author Vincent Petry
 * @copyright 2015 Vincent Petry <pvince81@owncloud.com>
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

use \Sabre\DAV\PropFind;
use \Sabre\DAV\PropPatch;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

class CustomPropertiesBackend implements \Sabre\DAV\PropertyStorage\Backend\BackendInterface {

	/**
	 * @var \Sabre\DAV\Tree
	 */
	private $tree;

	/**
	 * @var \OCP\IDBConnection
	 */
	private $connection;

	/**
	 * @var \OCP\IUser
	 */
	private $user;

	/**
	 * Properties cache
	 *
	 * @var array
	 */
	private $cache = [];

	/**
	 * @param \Sabre\DAV\Tree
	 */
	public function __construct(
		\Sabre\DAV\Tree $tree,
		\OCP\IDBConnection $connection,
		\OCP\IUser $user) {
		$this->tree = $tree;
		$this->connection = $connection;
		$this->user = $user->getUID();
	}

    /**
     * Fetches properties for a path.
     *
     * @param string $path
     * @param PropFind $propFind
     * @return void
     */
	public function propFind($path, PropFind $propFind) {
		$node = $this->tree->getNodeForPath($path);
		if (!($node instanceof \OC\Connector\Sabre\Node)) {
			return;
		}

		// TODO: pre-cache when $depth > 0

		$requestedProps = $propFind->get404Properties();
		if (empty($requestedProps)) {
			return;
		}

		$props = $this->getProperties($node, $requestedProps);
		foreach ($props as $propName => $propValue) {
			$propFind->set($propName, $propValue);
		}
	}

    /**
     * Updates properties for a path
     *
     * This method received a PropPatch object, which contains all the
     * information about the update.
     *
     * Usually you would want to call 'handleRemaining' on this object, to get;
     * a list of all properties that need to be stored.
     *
     * @param string $path
     * @param PropPatch $propPatch
	 *
     * @return void
     */
	public function propPatch($path, PropPatch $propPatch) {
		$node = $this->tree->getNodeForPath($path);
		if (!($node instanceof \OC\Connector\Sabre\Node)) {
			return;
		}

		$propPatch->handleRemaining(function($changedProps) use ($node) {
			return $this->updateProperties($node, $changedProps);
		});
	}

    /**
     * This method is called after a node is deleted.
     *
	 * @param string $path path of node for which to delete properties
     */
	public function delete($path) {
		$statement = $this->connection->prepare(
			'DELETE FROM `*PREFIX*properties` WHERE `userid` = ? AND `propertypath` = ?'
		);
		$statement->execute(array(\OC_User::getUser(), $path));
		$statement->closeCursor();

		unset($this->cache[$path]);
	}

    /**
     * This method is called after a successful MOVE
     *
     * This should be used to migrate all properties from one path to another.
     * Note that entire collections may be moved, so ensure that all properties
     * for children are also moved along.
     *
     * @param string $source
     * @param string $destination
	 *
     * @return void
     */
	public function move($source, $destination) {
		$nodeSource = $this->tree->getNodeForPath($source);
		$nodeDest = $this->tree->getNodeForPath($destination);
		if (!($nodeSource instanceof \OC\Connector\Sabre\Node)) {
			return;
		}
		if (!($nodeDest instanceof \OC\Connector\Sabre\Node)) {
			return;
		}

		$statement = $this->connection->prepare(
			'UPDATE `*PREFIX*properties` SET `propertypath` = ?' .
			' WHERE `userid` = ? AND `propertypath` = ?'
		);
		$statement->execute(array($destination, \OC_User::getUser(), $source));
		$statement->closeCursor();
	}

	/**
	 * Returns a list of properties for this nodes.;
	 * @param \OC\Connector\Sabre\Node $node
	 * @param array|null $requestedProperties requested properties or "null" for all
	 * @return array
	 * @note The properties list is a list of propertynames the client
	 * requested, encoded as xmlnamespace#tagName, for example:
	 * http://www.example.org/namespace#author If the array is empty, all
	 * properties should be returned
	 */
	private function getProperties(\OC\Connector\Sabre\Node $node, array $requestedProperties) {
		$path = $node->getPath();
		if (isset($this->cache[$path])) {
			return $this->cache[$path];
		}

		// TODO: chunking if more than 1000 properties
		$sql = 'SELECT * FROM `*PREFIX*properties` WHERE `userid` = ? AND `propertypath` = ?';

		$whereValues = array($this->user, $path);
		$whereTypes = array(null, null);

		if (!empty($requestedProperties)) {
			// request only a subset
			$sql .= ' AND `propertyname` in (?)';
			$whereValues[] = $requestedProperties;
			$whereTypes[] = \Doctrine\DBAL\Connection::PARAM_STR_ARRAY;
		}

		$result = $this->connection->executeQuery(
			$sql,
			$whereValues,
			$whereTypes
		);

		$props = [];
		while ($row = $result->fetch()) {
			$props[$row['propertyname']] = $row['propertyvalue'];
		}

		$result->closeCursor();

		$this->cache[$path] = $props;
		return $props;
	}

	/**
	 * Update properties
	 *
	 * @param \OC\Connector\Sabre\Node $node node for which to update properties
	 * @param array $properties array of properties to update
	 *
	 * @return bool
	 */
	public function updateProperties($node, $properties) {
		$path = $node->getPath();

		$deleteStatement = $this->connection->prepare(
			'DELETE FROM `*PREFIX*properties`' .
			' WHERE `userid` = ? AND `propertypath` = ? AND `propertyname` = ?'
		);

		$insertStatement = $this->connection->prepare(
			'INSERT INTO `*PREFIX*properties`' .
			' (`userid`,`propertypath`,`propertyname`,`propertyvalue`) VALUES(?,?,?,?)'
		);

		$updateStatement = $this->connection->prepare(
			'UPDATE `*PREFIX*properties` SET `propertyvalue` = ?' .
			' WHERE `userid` = ? AND `propertypath` = ? AND `propertyname` = ?'
		);

		// TODO: use "insert or update" strategy ?
		$existing = $this->getProperties($node, null);
		$this->connection->beginTransaction();
		foreach ($properties as $propertyName => $propertyValue) {
			// If it was null, we need to delete the property
			if (is_null($propertyValue)) {
				if (array_key_exists($propertyName, $existing)) {
					$deleteStatement->execute(
						array(
							\OC_User::getUser(),
							$path,
							$propertyName
						)
					);
					$deleteStatement->closeCursor();
				}
			} else {
				if (!array_key_exists($propertyName, $existing)) {
					$insertStatement->execute(
						array(
							\OC_User::getUser(),
							$path,
							$propertyName,
							$propertyValue
						)
					);
					$insertStatement->closeCursor();
				} else {
					$updateStatement->execute(
						array(
							$propertyValue,
							\OC_User::getUser(),
							$path,
							$propertyName
						)
					);
					$updateStatement->closeCursor();
				}
			}
		}

		$this->connection->commit();
		unset($this->cache[$path]);

		return true;
	}

}
