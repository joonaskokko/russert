<?php
namespace Russert;

use Russert\SourceInterface;

/**
 * Source class for shared functionalities.
 */

abstract class Source implements SourceInterface {
	protected $name = "";
	protected $link = "";
	protected $description = "";
	protected $items = [];
	protected $hidden = FALSE;
	protected $updated = FALSE;


	/**
	 * Constructor.
	 */

	function __construct() {
		// ":D"
	}


	/**
	 * Get the name of the source. This is in pretty format
	 * @return String The pretty name of the source.
	 * @author Joonas Kokko
	 */

	public function getName() : string {
		return $this->name;
	}


	/**
	 * Set name.
	 *
	 * @param string $name Source name.
	 * @return void
	 * @author Joonas Kokko
	 */

	public function setName($name) : void {
		$this->name = $name;
	}


	/**
	 * Return the class name of the object. This is only for convenience because we also need to strip the namespace away.
	 *
	 * @return String Name of the class.
	 * @author Joonas Kokko
	 */

	public function getClassName() : string {
		// https://coderwall.com/p/cpxxxw/php-get-class-name-without-namespace
		$name = (new \ReflectionClass($this))->getShortName();
		return $name;
	}


	/**
	 * Get link.
	 *
	 * @return String The link.
	 * @author Joonas Kokko
	 */

	public function getLink() : string {
		return $this->link;
	}


	/**
	 * Set link.
	 *
	 * @param string $link The link.
	 * @return void
	 * @author Joonas Kokko
	 */

	public function setLink($link) {
		$this->link = $link;
	}


	/**
	 * Get description
	 *
	 * @return String Description of the source.
	 * @author Joonas Kokko
	 */

	public function getDescription() : string {
		return $this->description;
	}


	/**
	 * Get info if the source is hidden from the list or not.
	 *
	 * @return boolean Flag status.
	 * @author Joonas Kokko
	 */

	public function getHidden() : bool {
		return $this->hidden;
	}


	/**
	 * Get updated flag.
	 *
	 * @return boolean Flag status.
	 * @author Joonas Kokko
	 */

	public function getUpdated() : bool {
		return $this->updated;
	}


	/**
	 * Switch updated status.
	 *
	 * @param bool $updated
	 * @return void
	 * @author Joonas Kokko
	 */

	public function setUpdated(bool $updated) : void {
		$this->updated = $updated;
	}


	/**
	 * Returns the HTML DOM.
	 *
	 * @param string $url Custom URL to call. Fallbacks to source's link.
	 * @return Mixed DOMDocument on success, false on fail.
	 */

	public function getHtmlDom(string $url = "") {
		if (!$url) {
			$url = $this->getLink();
		}

		// Set user-agent to Curl.
		$userAgent = "curl/" . curl_version()["version"];
		// Create a stream context with headers
		$options = [
			"http" => [
				"method" => "GET",
				"header" => "User-Agent: $userAgent\r\n"
			]
		];

		$context = stream_context_create($options);

		$html = file_get_contents($url, false, $context);

		if (!$html) {
			throw new \Exception("Couldn't get HTML from URL.", 5002);
		}

		$doc = new \DOMDocument();
		$doc->strictErrorChecking = FALSE;
		$html = mb_convert_encoding($html, "HTML-ENTITIES", "UTF-8");
		@$doc->loadHTML($html);

		// Couldn't even form a remotely valid DOMDocument.
		if (!$doc) {
			throw new \Exception("Couldn't parse HTML.", 5001);
		}

		return $doc;
	}
}
