<?php
PHP_SAPI == 'cli' or die();
require_once("config.php");

require_once __DIR__ . "/vendor/autoload.php";

//use PDO;
//use PDOException;

new Russert();

class Russert {
	private $sources = [];
	private $connection = NULL;
	private $collection = NULL;
	private $errors = [];
	private $locked = FALSE;
	private $start_time = NULL;
	private $end_time = NULL;

	// "Force" flags.
	private $generate_rss = FALSE;
	private $generate_html = TRUE;

	function __construct() {
		// Set start time.
		$this->start_time = microtime(TRUE);

		// Check lock.
		if ($this->isLocked()) {
			$this->log("Lock is in place.");
			die();
		}

		try {
			// Set lock.
			$this->setLock();

			// Connect to database.
			$this->connectDatabase();

			// Ensure schema.
			$this->ensureSchema();

			// Help variable for getting single source name.
			$single_source = NULL;

			// Check custom command-line parameters.
			if (!empty($_SERVER['argv']) && is_array($_SERVER['argv']) && count($_SERVER['argv']) > 1) {
				// Unset the first since it's the script name.
				unset($_SERVER['argv'][0]);

				foreach ($_SERVER['argv'] as $argument) {
					if (strpos($argument, "--source=") !== FALSE) {
						$single_source = explode("--source=", $argument)[1];

						// Skipt index generation.
						$this->generate_html = FALSE;
						continue;
					}
					else if ($argument == "--force-rss") {
						$this->log("Forcing RSS generation.");
						$this->generate_rss = TRUE;
						continue;
					}
					elseif ($argument == "--help" || $argument == "-h") {
						echo "Russert RSS file generator\n";
						echo "Usage: php russert.php [OPTIONS]\n";
						echo "    --source=SOURCE NAME   Only process a single source.\n";
						echo "    --force-rss            Forces regeneration of RSS files.\n";
						echo "-h, --help                 Display this message\n";
						die();
					}
				}
			}

			$sources = [];

			// Load all available sources if the single source mode isn't on.
			if ($single_source) {
				$sources = $this->getSourceFilenames($single_source);
			}
			else {
				$sources = $this->getSourceFilenames();
			}

			if (!$sources) {
				$this->log("No sources found.");
				die();
			}

			// Load the source objects to $this->sources.
			$this->loadSources($sources);

			// Handle the sources and get updates.
			$this->handleSources();

			// Output RSS files.
			$this->handleRssFiles();

			// Generate HTML index file but only if not running single source mode.
			$this->handleIndex();
		}
		catch (Exception $e) {
			$this->log("Things went wrong: {$e->getMessage()}.", TRUE);
		}
	}


	/**
	 * Do these when we are quitting.
	 */

	function __destruct() {
		// Kill database connection.
		unset($this->connection);

		// Handle errors.
		$this->handleErrors();

		// Remove lock if it has been set on this run.
		if ($this->locked) {
			$this->freeLock();
		}

		// Time.
		$this->end_time = microtime(TRUE);
		$this->log("Process took " . (round($this->end_time - $this->start_time)) . " seconds.");

		$this->log("Bye!");
	}


	/**
	 * Returns a list of source filenames in the folder.
	 * @param String $name A name filter for the query.
	 *
	 * @return Array Array of sources found.
	 */

	function getSourceFilenames(string $name = "") : array {
		$file_query = SOURCE_FOLDER . "/*.php";

		if (!empty($name)) {
			$file_query = SOURCE_FOLDER . "/{$name}.php";
		}

		$files = glob($file_query);
		$sources = [];

		if ($files) {
			foreach ($files as &$file) {
				$file = explode("/", $file);
				$file = end($file);
				$file = explode(".php", $file);
				$file = reset($file);

				// See if the class exists.
				// FIXME: Get this from somewhere else?
				$namespace = "\\Russert\\Sources\\";

				// Filename here.
				$source = $namespace . $file;

				if (class_exists($source)) {
					// These all must come from "Source" class.
					$source_object = new $source;

					// FIXME: Get this from somewhere else?
					if (is_subclass_of($source_object, "\\Russert\\Source")) {
						$sources[] = $source;
					}
				}
				else {
					$this->log("Loading source {$source} failed.");
				}
			}
		}

		return $sources;
	}


	/**
	 * Loads the sources into memory.
	 *
	 * @param array $sources Flat list of source names to load. This is because of single source support.
	 * @return void
	 * @author Joonas Kokko
	 */

	function loadSources(array $sources) {
		foreach ($sources as $source) {
			$this->sources[] = new $source;
		}
	}


	/**
	 * Handles all sources.
	 *
	 * @return Boolean True/false on success/fail.
	 */

	function handleSources() : bool {
		foreach ($this->sources as &$source) {
			$this->handleCargo($source);
		}

		return FALSE;
	}


	/**
	 * Handles individual set of cargo coming from the source.
	 */

	function handleCargo(object $source) : void {
		$this->log("Checking new items for {$source->getName()}...");
		try {
			$cargo = $source->getCargo();

			if (DEBUG_MODE) {
				print_r($cargo);
			}

			if (!empty($cargo)) {
				foreach ($cargo as $item) {
					// Try getting the same item from the database.
					if ($this->itemExists($item)) {
							$this->log("Item already exists, skipping: {$item['guid']}");
					}
					else {
						// Insert the item.
						$this->log("Item found, saving: {$item['guid']}");
						$this->saveItem($item, $source);

						// Tell the source that it got updated so we'll re-generate RSS for it.
						$source->setUpdated(TRUE);
					}
				}
			}
			else {
				$this->log("Couldn't get any items from {$source->getName()}, DOM changed?", TRUE);
			}
		}
		catch (Exception $e) {
			$this->log("Couldn't handle cargo: {$e->getMessage()}.");
		}
	}


	/**
	 * Handles RSS files to the disk.
	 *
	 * @return Boolean True on success, false on fail.
	 */

	function handleRssFiles() : bool {
		if ($this->sources) {
			// Get sources that were updated.
			if (!$this->generate_rss) {
				$sources = array_filter($this->sources, function($o) {
					if ($o->getUpdated()) {
						return TRUE;
					}
				});
			}
			else {
				$sources = $this->sources;
			}

			if (!empty($sources)) {
				foreach ($sources as $source) {
					$items = $this->getLatestItemsBySource($source);

					if ($items) {
						$this->log("Generating RSS feed for {$source->getName()}");
						$this->saveRssFile($items, $source);
					}
				}
			}
			else {
				$this->log("No updates to RSS files.");
			}
		}

		return FALSE;
	}


	/**
	 * Saves the RSS file to the disk using items and the source.
	 *
	 * @return Boolean True on success, false on fail.
	 */

	function saveRssFile(array $items, object $source) : bool {
		ob_start();
		require("rss.tpl.php");
		$xml = ob_get_contents();
		ob_end_clean();

		$filename = RSS_FOLDER . "/" . $source->getClassName() . ".xml";

		if (!DEBUG_MODE) {
			if (@file_put_contents($filename, $xml)) {
				$this->log("RSS file saved.");
				return TRUE;
			}
			else {
				throw new \Exception("Couldn't save RSS file {$filename}.", 5112);
			}
		}
		else {
			$this->log("Would save RSS file to {$filename}.");
		}

		return FALSE;
	}


	/**
	 * Saves index.html into the RSS folder.
	 *
	 * @return void
	 * @author Joonas Kokko
	 */

	function handleIndex() {
		if (!$this->generate_html) {
			$this->log("Skipping index generation due to single source mode.");
			return TRUE;
		}

		$this->log("Generating index file.");
		$source_filenames = [];

		$visible_sources = array_filter($this->sources, function($o) {
			if ($o->getHidden() == FALSE) {
				return TRUE;
			}
		});

		// Get filenames
		if (!empty($visible_sources)) {
			$this->saveIndexFile($visible_sources);
			$this->log("Index file updated.");
		}
		else {
			$this->log("No visible souces.");
		}
	}


	/**
	 * Saves an HTML index file to the disk.
	 * TODO: Exceptions.
	 *
	 * @param array $sources
	 * @return boolean Success true/false.
	 * @author Joonas Kokko
	 */

	function saveIndexFile(array $sources) : bool {
		ob_start();
		require("index.tpl.php");
		$html = ob_get_contents();
		ob_end_clean();

		$filename = RSS_FOLDER . "/" . "index.html";

		if (!DEBUG_MODE) {
			if (file_put_contents($filename, $html)) {
				return TRUE;
			}
		}
		else {
			$this->log("Would save index file to {$filename}.");
		}

		return TRUE;
	}


	/**
	 * Check if item exists.
	 * @param Array $item Item array.
	 *
	 * @return Boolean True if the item exists, false if it doesn't.
	 */

	function itemExists(array $item) : bool {
		if (empty($item['guid'])) {
			throw new \Exception("Missing guid.", 5014);
		}

		$guid = $item['guid'];
		$existing_item = NULL;

		try {
			$existing_item = $this->getItemByGuid($guid);
		}
		catch (Exception $e) {
			return FALSE;
		}

		return TRUE;
	}


	/**
	 * Returns an item from database by GUID.
	 *
	 * @return Object Item Object.
	 */

	function getItemByGuid(string $guid) : object {
		if (empty($guid)) {
			throw new \Exception("Invalid guid.", 5013);
		}

		$sql = "SELECT id, title, link, guid, seen, source FROM item WHERE guid = :guid LIMIT 1";
		try {
			$stmt = $this->connection->prepare($sql);
			$stmt->bindValue(':guid', $guid, PDO::PARAM_STR);
			$stmt->execute();

			$row = $stmt->fetch(PDO::FETCH_OBJ);
			if ($row) {
				return $row;
			}
			else {
				throw new \Exception("No item found.", 5012);
			}
		}
		catch (PDOException $e) {
			throw new \Exception("Database error while fetching item: {$e->getMessage()}.", 5012);
		}
	}

	/**
	 * Returns an array of latest items by source.
	 * @param Object $source Source object..
	 * @param Integer $limit How many to return at most.
	 *
	 * @return Mixed Array of items or false if nothing found.
	 */

	function getLatestItemsBySource(object $source, int $limit = 20) : array {
		$sql = "SELECT id, title, link, guid, seen, source
			FROM item
			WHERE source = :source
			ORDER BY seen DESC
			LIMIT :limit";

		try {
			$stmt = $this->connection->prepare($sql);
			$stmt->bindValue(':source', $source->getClassName(), PDO::PARAM_STR);
			$stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
			$stmt->execute();

			$rows = $stmt->fetchAll(PDO::FETCH_OBJ);

			return $rows ?: [];
		}
		catch (PDOException $e) {
			throw new \Exception("Getting latest items from source failed: {$e->getMessage()}.", 5010);
		}
	}

	/**
	 * Save item.
	 * @param Array $item Item array to be saved.
	 * @param Object $source The source object.
	 *
	 * @return Boolean True or false on success/fail.
	 */

	function saveItem(array $item, object $source) : void {
		// Validate the item.
		if (!$this->isItemValid($item)) {
			throw new \Exception("Item is not valid.", 5009);
		}

		// Set date (MySQL DATETIME)
		$seen = date('Y-m-d H:i:s');

		// Add source.
		$sourceName = $source->getClassName();

		// Debug saving..
		if (DEBUG_MODE) {
			$this->log("Would insert: " . json_encode(array_merge($item, ['seen' => $seen, 'source' => $sourceName])));
			return;
		}

		// Actual save.
		$sql = "INSERT INTO item (title, link, guid, seen, source)
		        VALUES (:title, :link, :guid, :seen, :source)";

		try {
			$stmt = $this->connection->prepare($sql);
			$stmt->bindValue(':title', $item['title'], PDO::PARAM_STR);
			$stmt->bindValue(':link', $item['link'], PDO::PARAM_STR);
			$stmt->bindValue(':guid', $item['guid'], PDO::PARAM_STR);
			$stmt->bindValue(':seen', $seen, PDO::PARAM_STR);
			$stmt->bindValue(':source', $sourceName, PDO::PARAM_STR);
			$stmt->execute();
		}
		catch (PDOException $e) {
			throw new \Exception("Inserting item to database failed: {$e->getMessage()}.", 5008);
		}
	}

	/**
	 * A simple item validator.
	 * @param Array $item Item to be validated.
	 *
	 * @return Boolean True if the item is valid, false if not.
	 */

	function isItemValid(array $item) : bool {
		$keys = [ "title", "link", "guid" ];

		foreach ($keys as $key) {
			if (empty($item[$key])) {
				$this->log("Item is not valid: Missing key {$key}.");
				return FALSE;
			}
		}

		return TRUE;
	}


	/**
	 * Sets lock.
	 *
	 * @return void
	 * @author Joonas Kokko
	 */

	function setLock() : void {
		// Lock is disabled.
		if (DEBUG_MODE) {
			return;
		}

		$result = file_put_contents(LOCKFILE, "Locked as of " . date('c'));

		if (!$result) {
			throw new \Exception("Error writing lockfile.", 5007);
		}

		// This will tell the program that the lock has been set within this run.
		$this->locked = TRUE;
	}


	/**
	 * See if we are locked or not.
	 *
	 * @return Boolean True/false.
	 */

	function isLocked() : bool {
		// Lock is disabled.
		if (DEBUG_MODE) {
			return FALSE;
		}

		if (file_exists(LOCKFILE)) {
			$created = filectime(LOCKFILE);
			$check = time() - (LOCKFILE_MINUTES * 60);

			if ($created > $check) {
				return TRUE;
			}
		}

		return FALSE;
	}


	/**
	 * Free the lockfile.
	 *
	 * @return void
	 * @author Joonas Kokko
	 */

	function freeLock() : void {
		if (!file_exists(LOCKFILE)) {
			throw new \Exception("File doesn't exist.", 5006);
		}

		if (!unlink(LOCKFILE)) {
			throw new \Exception("Removing lockfile failed.", 5005);
		}

		$this->locked = FALSE;
	}


	/* --- Other internals --- */

	/**
	 * Connects to the database and sets $this->connection.
	 *
	 * @return void
	 */

	function connectDatabase() : void {
		try {
			if (DATABASE_DRIVER == 'sqlite') {
				$this->connection = new PDO('sqlite::memory:');
				return;
			}

			$this->connection = new PDO(
				"mysql:host=" . MYSQL_HOST . ";dbname=" . MYSQL_DATABASE . ";charset=utf8mb4",
				MYSQL_USER,
				MYSQL_PASSWORD,
				[
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES => false,
				]
			);
		}
		catch (Exception $e) {
			throw new \Exception("Connecting to MySQL failed: {$e->getMessage()}.", 5004);
		}
	}


	/**
	 * Ensure required SQL schema exists.
	 * Creates tables if they are missing.
	 *
	 * @return void
	 * @throws Exception On schema creation failure.
	 */

	function ensureSchema() : void {
		$this->log("Ensuring database schema.");
		try {
			$driver = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);

			if ($driver === 'mysql') {
				// MySQL: check table existence via INFORMATION_SCHEMA
				$sql = "
					SELECT COUNT(*)
					FROM information_schema.tables
					WHERE table_schema = DATABASE()
					  AND table_name = 'item'
				";

				$exists = (int) $this->connection->query($sql)->fetchColumn();

				if ($exists === 0) {
					$this->connection->exec("
						CREATE TABLE item (
							id INT AUTO_INCREMENT PRIMARY KEY,
							title VARCHAR(1024) NOT NULL,
							link VARCHAR(2048) NOT NULL,
							guid VARCHAR(512) NOT NULL,
							seen DATETIME NOT NULL,
							source VARCHAR(255) NOT NULL,
							UNIQUE KEY uniq_guid (guid),
							KEY idx_source (source),
							KEY idx_seen (seen)
						) ENGINE=InnoDB
						  DEFAULT CHARSET=utf8mb4
						  COLLATE=utf8mb4_unicode_ci
					");
				}
			}
			elseif ($driver === 'sqlite') {
				// SQLite: check sqlite_master
				$sql = "
					SELECT name
					FROM sqlite_master
					WHERE type='table' AND name='item'
				";

				$stmt = $this->connection->prepare($sql);
				$stmt->execute();
				$exists = $stmt->fetchColumn();

				if (!$exists) {
					$this->connection->exec("
						CREATE TABLE item (
							id INTEGER PRIMARY KEY AUTOINCREMENT,
							title TEXT NOT NULL,
							link TEXT NOT NULL,
							guid TEXT NOT NULL UNIQUE,
							seen TEXT NOT NULL,
							source TEXT NOT NULL
						)
					");

					// Optional but recommended indexes
					$this->connection->exec("CREATE INDEX idx_item_source ON item(source)");
					$this->connection->exec("CREATE INDEX idx_item_seen ON item(seen)");
				}
			}
			else {
				throw new Exception("Unsupported database driver: {$driver}");
			}
		}
		catch (PDOException $e) {
			throw new Exception("Ensuring database schema failed: {$e->getMessage()}", 5001);
		}
	}


	/**
	 * Handles encountered errors by printing them out and mailing them.
	 */

	function handleErrors() : void {
		if ($this->errors) {
			$this->log("Encountered " . count($this->errors) . " serious errors:");

			foreach ($this->errors as $error) {
				$this->log($error);
			}

			if (REPORT_EMAIL) {
				$this->sendMails($this->errors);
			}
		}
	}


	/**
	 * Compile one mail from a set of errors.
	 */

	function sendMails(array $mails) : void {
		if ($mails && !DEBUG_MODE) {
			$this->log("Sending " . count($mails) . " errors(s) to " . REPORT_EMAIL . ".");
			$message = "";

			foreach ($mails as $mail) {
				$message .= $mail . "\n";
			}

			$result = mail(REPORT_EMAIL, "Critical Russert error(s)", $message);

			if (!$result) {
				throw new \Exception("Sending mail failed due to unknown error.", 5003);
			}
		}
	}


	/**
	 * A simple log function
	 * @param String $message A message to be logged.
	 * @param Boolean $error If the thing is an error and should be notified via email.
	 */

	function log(string $message, bool $error = FALSE) : void {
		// Timestamp.
		echo terminal_style(date('c') . ": ", "info");

		if ($error) {
			echo terminal_style($message, "red") . "\n";
			// Add the message to errors.
			$this->errors[] = $message;
		}
		else {
			echo terminal_style($message, "green") . "\n";
		}
	}
}
