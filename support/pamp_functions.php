<?php
	// Portable Apps mirror/proxy support functions.
	// (C) 2021 CubicleSoft.  All Rights Reserved.

	function PAMP_LoadConfig()
	{
		global $rootpath;

		$config = @json_decode(file_get_contents($rootpath . "/config.dat"), true);
		if (!is_array($config))  $config = array();

		$defaults = array(
			"server_ip" => "0.0.0.0",
			"server_port" => 15644,
			"storage_dir" => $rootpath . "/data",
			"langs" => array("Default"),
			"apps" => array()
		);

		$config = $config + $defaults;

		// Must ALWAYS have "PortableApps.com".
		if (!in_array("PortableApps.com", $config["apps"]))  $config["apps"][] = "PortableApps.com";

		@mkdir($config["storage_dir"]);

		return $config;
	}

	function PAMP_LoadUpdateINI($filename)
	{
		$appmap = parse_ini_file($filename, true, INI_SCANNER_RAW);
		if (!is_array($appmap))  return array("success" => false, "error" => "Unable to read INI file.", "error_code" => "parse_ini_file_failed");

		return array("success" => true, "appmap" => $appmap);
	}
?>