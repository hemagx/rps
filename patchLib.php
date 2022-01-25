<?php
namespace patchLib;

class patchLib
{
	private $configPath;
	private $config;
	private $patchList;
	private $checksumList;

	/**
	 * Constructs the library with it options
	 * @param string $configPath path to configuration file
	 */
	function __construct(string $configPath = "config.yaml")
	{
		$this->configPath = $configPath;
	}

	/**
	 * Initiate the library, parsing config file, validation of it values and loading patch list
	 * @return boolean
	 **/
	function init()
	{
		if (!$this->parseConfig())
			return false;

		if (!$this->validateConfig())
			return false;

		$retVal = true;
		foreach($this->config["servers"] as $server) {
			if (!$this->loadPatchList($server))
				$retVal = false;

			if (isset($server["checksum_list"]))
				$this->loadChecksumList($server);
		}

		return $retVal;
	}

	/**
	 * Parse config file
	 * @return boolean
	 */
	private function parseConfig()
	{
		if (($config = yaml_parse_file($this->configPath)) === FALSE) {
			printf("[Error]: Failed to parse config file\n");
			return false;
		}

		$this->config = $config;
		return true;
	}

	/**
	 * Validates config entries
	 * @return boolean
	 **/
	private function validateConfig()
	{
		$retVal = true;

		foreach($this->config["servers"] as $server) {
			if (!$this->validateServer($server))
				$retVal = false;
		}

		return $retVal;
	}

	/**
	 * Validates a server entry in config
	 * @param array $server server config entry
	 * @return boolean
	 **/
	private function validateServer(array $server)
	{
		if (!file_exists($server["output_dir"])) {
			printf("[Error]:%s: output directory '%s' doesn't exists\n", $server["server"], $server["output_dir"]);
			return false;
		}

		if (!is_dir($server["output_dir"])) {
			printf("[Error]:%s: output directory '%s' is not a directory\n", $server["server"], $server["output_dir"]);
			return false;
		}

		if (!is_writable($server["output_dir"]) || !is_readable($server["output_dir"])) {
			printf("[Error]:%s: output directory '%s' is not a readable/writable\n", $server["server"], $server["output_dir"]);
			return false;
		}

		if (!$this->checkRemotePathExists($server["patch_list"])) {
			printf("[Error]:%s: patch list '%s' doesn't exists\n", $server["server"], $server["patch_list"]);
			return false;
		}

		if (!$this->checkRemotePathExists($server["patch_dir"])) {
			printf("[Error]:%s: patch directory '%s' doesn't exists\n", $server["server"], $server["patch_dir"]);
			return false;
		}

		return true;
	}

	/**
	 * Loads a server patch list and saves it as an array in $this->patchList
	 * @param array $server server config entry
	 * @return boolean
	 **/
	private function loadPatchList(array $server)
	{
		if (!$this->downloadRemotePath($server["patch_list"], $output)) {
			printf("[Error]:%s: failed to download patch list '%s'\n", $server["server"], $server["patch_list"]);
			return false;
		}

		// Detect line ending
		if (strstr($output, "\r\n") !== false)
			$eol = "\r\n";
		else
			$eol = "\n";

		$line = strtok($output, $eol);

		while ($line !== false) {
			if ($this->parsePatchListLine($line, $patchId, $fileName)) {
				$this->patchList[$server["server"]][] = ["id" => $patchId, "filename" => $fileName];
			}

			$line = strtok($eol);
		}
		return true;
	}

	/**
	 * Parses a line from patch list
	 * Returns true if the line contains known format, false on comments or invalid lines.
	 * @param string $line the line to be parsed
	 * @param int &$patchId patch id
	 * @param string &$fileName file name
	 * @return boolean
	 **/
	private function parsePatchListLine(string $line, &$patchId, &$fileName)
	{
		$patchId = '';
		$fileName = '';
		$line = trim($line);

		if ($line[0] == '/' && $line[1] == '/')
			return false;

		for ($i = 0; $i < strlen($line); ++$i) {
			if (!is_numeric($line[$i]))
				break;

			$patchId .= $line[$i];
		}

		if (strlen($patchId) < 1)
			return false;

		$patchId = intval($patchId);

		for (; $i < strlen($line); ++$i) {
			if (ctype_space($line[$i]))
				continue;

			$fileName .= $line[$i];
		}

		if (strlen($fileName) < 1)
			return false;

		return true;
	}

	/**
	 * Loads a server checksum list and saves it as an array in $this->checksumList
	 * @param array $server server config entry
	 * @return boolean
	 **/
	private function loadChecksumList(array $server)
	{
		if (!$this->downloadRemotePath($server["checksum_list"], $output)) {
			printf("[Error]:%s: failed to download checksum list '%s'\n", $server["server"], $server["checksum_list"]);
			return false;
		}

		$checksumList = json_decode($output, true);

		if ($checksumList === null) {
			printf("[Error]:%s: failed to decode checksum list '%d'\n", $server["server"], json_last_error());
			return false;
		}

		$this->checksumList[$server["server"]] = $checksumList;
		return true;
	}

	/**
	 * downloads a file over HTTP
	 * @param string $uri the url to path
	 * @param &$output a reference to output
	 * @return boolean
	 **/
	private function downloadRemotePath(string $uri, &$output)
	{
		$retVal = true;
		$ch = curl_init($uri);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$output = curl_exec($ch);

		if (curl_errno($ch) === 0) {
			$info = curl_getinfo($ch);
			if ($info['http_code'] != 200)
				$retVal = false;
		} else {
			printf("[Error]: cURL error for '%s' %s\n", curl_error($ch));
			$retVal = false;
		}

		curl_close($ch);
		return $retVal;
	}

	/**
	 * Checks if http remote uri exists
	 * @param string $uri the url to path
	 * @return boolean
	 **/
	private function checkRemotePathExists(string $uri)
	{
		$retVal = true;
		$ch = curl_init($uri);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_exec($ch);

		if (curl_errno($ch) === 0) {
			$info = curl_getinfo($ch);
			if ($info['http_code'] != 200)
				$retVal = false;
		} else {
			printf("[Error]: cURL error for '%s' %s\n", curl_error($ch));
			$retVal = false;
		}

		curl_close($ch);
		return $retVal;
	}

	/**
	 * Initiate the sync process
	 * @return boolean
	 **/
	public function sync()
	{
		foreach($this->config["servers"] as $server) {
			printf("[Status]: Starting to sync %s\n", $server["server"]);
			if (!$this->downloadPatches($server))
				return false;
		}

		return true;
	}

	/**
	 * Download the patches for this specific server, saves it in output directory and update patch state
	 * @param array $server server config entry
	 * @return boolean
	 **/
	private function downloadPatches(array $server)
	{
		$patchStatePath = $server["output_dir"] . '/' . $server["patch_track_file"];
		$patchStateId = $this->loadPatchState($patchStatePath);

		for ($i = 0; $i < count($this->patchList[$server["server"]]); ++$i) {
			if ($patchStateId < $this->patchList[$server["server"]][$i]["id"])
				break;
		}

		$patchesCount = count($this->patchList[$server["server"]]) - $i;
		if ($patchesCount < 1) {
			printf("[Status]:%s: is up-to-date\n", $server["server"]);
			return true;
		}

		printf("[Status]:%s: Starting at patch %d - %s (%d patches to download)\n",
			$server["server"], $this->patchList[$server["server"]][$i]["id"], $this->patchList[$server["server"]][$i]["filename"], $patchesCount);

		$n = 1;
		for (; $i < count($this->patchList[$server["server"]]); ++$i) {
			$file = '';

			for ($k = 0; $k < 5; ++$k) {
				printf("[Status]:%s: %d/%d Trying to download %s\n", $server["server"], $n, $patchesCount, $this->patchList[$server["server"]][$i]["filename"]);
				if (!$this->downloadRemotePath($server["patch_dir"] . $this->patchList[$server["server"]][$i]["filename"], $file))
					continue;
				break;
			}

			if ($k >= 5) {
				printf("[Error]:%s: Failed to download %s aborting...\n", $server["server"], $this->patchList[$server["server"]][$i]["filename"]);
				return false;
			}

			if (!$this->validateChecksum($server, $this->patchList[$server["server"]][$i]["filename"], $file))
				return false;

			$fp = fopen($server["output_dir"] . '/' . $this->patchList[$server["server"]][$i]["filename"], "wb");
			if ($fp === false) {
				printf("[Error]:%s: Failed to open %s aborting...\n", $server["server"], $this->patchList[$server["server"]][$i]["filename"]);
				fclose($fp);
				return false;
			}

			if (fwrite($fp, $file) === false) {
				printf("[Error]:%s: Failed to write %s aborting...\n", $server["server"], $this->patchList[$server["server"]][$i]["filename"]);
				fclose($fp);
				return false;
			}

			if (file_put_contents($patchStatePath, $this->patchList[$server["server"]][$i]["id"]) === false) {
				printf("[Error]:%s: Failed to write patch state aborting...\n", $server["server"]);
				fclose($fp);
				return false;
			}

			++$n;
			fclose($fp);
		}
		return true;
	}

	/**
	 * Reads the patch state file and returns last known patch id
	 * @param string $path the path of the state file
	 * @return int last known patch id
	 **/
	private function loadPatchState(string $path)
	{
		if (!file_exists($path))
			return 0;

		return intval(file_get_contents($path));
	}

	/**
	 * Validate the patch checksum/size if possible
	 * @param array  $server server config entry
	 * @param string $fileName the file name
	 * @param string $file file buffer
	 * @return boolean
	 **/
	private function validateChecksum(array $server, string $fileName, string $file)
	{
		$fileName = strtolower($fileName); // Ai4rei list uses lower case

		if (!isset($this->checksumList[$server["server"]]))
			return true;

		if (!isset($this->checksumList[$server["server"]][$fileName])) {
			printf("[Status]:%s: File %s doesn't have an entry in checksum list\n", $server["server"], $fileName);
			return true;
		}

		$md5digest = md5($file);
		if ($this->checksumList[$server["server"]][$fileName]["hash"] !== $md5digest) {
			printf("[Error]:%s: File %s doesn't match checksum %s calculated checksum %s\n",
				$this->checksumList[$server["server"]][$fileName]["hash"], $md5digest);
			return false;
		}

		$size = strlen($file);
		if ($this->checksumList[$server["server"]][$fileName]["size"] !== $size) {
			printf("[Error]:%s: File %s size doesn't match %d downloaded size %d\n",
				$this->checksumList[$server["server"]][$fileName]["size"], $size);
			return false;
		}

		return true;
	}
}
