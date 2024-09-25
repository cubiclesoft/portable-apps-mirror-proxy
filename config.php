<?php
	// Portable Apps mirror/proxy configuration tool.
	// (C) 2024 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/pamp_functions.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"allow_opts_after_param" => false
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "The Portable Apps mirror/proxy configuration tool\n";
		echo "Purpose:  Configure the Portable Apps mirror/proxy and install the system service from the command-line.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [cmdgroup cmd [cmdoptions]]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " config\n";
		echo "\tphp " . $args["file"] . " apps add\n";
		echo "\tphp " . $args["file"] . " service install\n";

		exit();
	}

	$origargs = $args;
	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	// Get the command group.
	$cmdgroups = array(
		"config" => "Manage the server-level configuration",
		"langs" => "Manage mirrored app languages (e.g. English)",
		"apps" => "Manage locally mirrored applications",
		"service" => "Manage the system service"
	);

	$cmdgroup = CLI::GetLimitedUserInputWithArgs($args, false, "Command group", false, "Available command groups:", $cmdgroups, true, $suppressoutput);

	// Get the command.
	switch ($cmdgroup)
	{
		case "config":  $cmds = false;  break;
		case "langs":  $cmds = array("list" => "List app languages", "add" => "Add a language", "remove" => "Remove a language");  break;
		case "apps":  $cmds = array("sync" => "Perform a synchronization", "list" => "List locally mirrored apps", "import-existing" => "Auto-select locally mirrored apps via a local Portable Apps directory", "add" => "Add a locally mirrored app", "remove" => "Remove a locally mirrored app");  break;
		case "service":  $cmds = array("install" => "Install the system service", "remove" => "Remove the system service");  break;
	}

	if ($cmds !== false)  $cmd = CLI::GetLimitedUserInputWithArgs($args, false, "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	// Load the configuration.
	$config = PAMP_LoadConfig();

	function SaveConfig()
	{
		global $rootpath, $config;

		file_put_contents($rootpath . "/config.dat", json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		chmod($rootpath . "/config.dat", 0640);
	}

	if ($cmdgroup === "config")
	{
		CLI::ReinitArgs($args, array("ip", "port", "storage_dir"));

		$config["server_ip"] = CLI::GetUserInputWithArgs($args, "ip", "Bind IP", $config["server_ip"], "", $suppressoutput);
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Bind port", $config["server_port"], "", $suppressoutput);
		if ($port > 0 && $port < 65536)  $config["server_port"] = $port;

		$config["storage_dir"] = CLI::GetUserInputWithArgs($args, "storage_dir", "Storage Directory", $config["storage_dir"], "", $suppressoutput);

		SaveConfig();

		$result = array(
			"success" => true,
			"server" => $config["server"]
		);

		CLI::DisplayResult($result);
	}
	else if ($cmdgroup === "langs")
	{
		if ($cmd === "list")
		{
			$result = array(
				"success" => true,
				"langs" => $config["langs"]
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add")
		{
			CLI::ReinitArgs($args, array("lang"));

			// Scan the INI file and extract the list of languages.
			if (!file_exists($config["storage_dir"] . "/update_orig.ini"))  CLI::DisplayError("Unable to perform task since the required update file is missing.  Run 'php config.php apps sync' first.");

			$result = PAMP_LoadUpdateINI($config["storage_dir"] . "/update_orig.ini");
			if (!$result["success"])  CLI::DisplayError("Failed to load INI.", $result);

			$appmap = $result["appmap"];

			$langs = array();
			foreach ($appmap as $appkey => $info)
			{
				foreach ($info as $key => $val)
				{
					if (substr($key, 0, 13) === "DownloadFile_")  $langs[substr($key, 13)] = substr($key, 13);
				}
			}

			ksort($langs);

			$langs = array("Default" => "Default", "All" => "Add all languages") + $langs;

			// Remove already selected languages from the list.
			foreach ($config["langs"] as $lang)  unset($langs[$lang]);

			if (!count($langs))  CLI::DisplayError("No languages found.");

			$lang = CLI::GetLimitedUserInputWithArgs($args, "lang", "Language", false, "Available languages:", $langs, true, $suppressoutput);

			if ($lang !== "All")  $config["langs"][] = $lang;
			else
			{
				foreach ($langs as $lang2 => $val)
				{
					if ($lang !== "All")  $config["langs"][] = $lang2;
				}
			}

			sort($config["langs"], SORT_NATURAL | SORT_FLAG_CASE);
			$config["langs"] = array_values($config["langs"]);

			SaveConfig();

			$result = array(
				"success" => true,
				"lang" => $lang
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			CLI::ReinitArgs($args, array("lang"));

			if (!count($config["langs"]))  CLI::DisplayError("No languages found.");

			$num = CLI::GetLimitedUserInputWithArgs($args, "lang", "Language", false, "Available languages:", $config["langs"], true, $suppressoutput);

			array_splice($config["langs"], $num, 1);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
	}
	else if ($cmdgroup === "apps")
	{
		if ($cmd === "sync")
		{
			require_once $rootpath . "/support/http.php";
			require_once $rootpath . "/support/tag_filter.php";
			require_once $rootpath . "/support/web_browser.php";
			require_once $rootpath . "/support/process_helper.php";

			// Create the MITM proxy root CA.
			$certscmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/php-ssl-certs/ssl-certs.php") . " -s";
			if (!file_exists($rootpath . "/php-ssl-certs/cache/root/root_cert.pem"))
			{
				if (!$suppressoutput)  echo "Creating MITM proxy root CA (this will probably take a bit)...";

				// Initialize.
				if (!file_exists($rootpath . "/php-ssl-certs/certs/root.json"))
				{
					$cmd = $certscmd . " init root -ca Y";

					$result = ProcessHelper::StartProcess($cmd);
					if (!$result)  CLI::DisplayError("Unable to start process.", $result);

					$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
					if (!file_exists($rootpath . "/php-ssl-certs/certs/root.json"))  CLI::DisplayError("Unable to create root CA.", $result2);
					$result3 = @json_decode($result2["stdout"], true);
					if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create root CA.", $result3);
				}

				// Generate CSR and private key.
				$cmd = $certscmd . " csr root -bits 4096 -digest sha256 -domain \"\" -keyusage \"\" -country \"\" -state \"\" -city \"\" -org \"\" -orgunit \"\" -email \"\" -commonname \"Portable Apps MITM Proxy Root CA\"";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create root CA CSR.", $result3);

				// Self-sign CSR.
				$cmd = $certscmd . " self-sign root -ca Y -days 3650 -digest sha256";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to sign root CA.", $result3);

				// Export certificate.
				$cmd = $certscmd . " export root";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to export root CA.", $result3);

				if (!$suppressoutput)  echo "Done.\n";
			}

			// Create the MITM proxy intermediate CA.
			if (!file_exists($rootpath . "/php-ssl-certs/cache/intermediate/intermediate_cert.pem"))
			{
				if (!$suppressoutput)  echo "Creating MITM proxy intermediate CA (this will probably take a bit)...";

				// Initialize.
				if (!file_exists($rootpath . "/php-ssl-certs/certs/intermediate.json"))
				{
					$cmd = $certscmd . " init intermediate -ca Y";

					$result = ProcessHelper::StartProcess($cmd);
					if (!$result)  CLI::DisplayError("Unable to start process.", $result);

					$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
					if (!file_exists($rootpath . "/php-ssl-certs/certs/intermediate.json"))  CLI::DisplayError("Unable to create intermediate CA.", $result2);
					$result3 = @json_decode($result2["stdout"], true);
					if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create intermediate CA.", $result3);
				}

				// Generate CSR and private key.
				$cmd = $certscmd . " csr intermediate -bits 4096 -digest sha256 -domain \"\" -keyusage \"\" -country \"\" -state \"\" -city \"\" -org \"\" -orgunit \"\" -email \"\" -commonname \"Portable Apps MITM Proxy Intermediate CA\"";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create intermediate CA CSR.", $result3);

				// Sign CSR.
				$cmd = $certscmd . " sign root intermediate -ca Y -days 3650 -digest sha256 -bits 4096 -redo N";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to sign intermediate CA.", $result3);

				// Export certificate.
				$cmd = $certscmd . " export intermediate";

				$result = ProcessHelper::StartProcess($cmd);
				if (!$result)  CLI::DisplayError("Unable to start process.", $result);

				$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
				$result3 = @json_decode($result2["stdout"], true);
				if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to export intermediate CA.", $result3);

				if (!$suppressoutput)  echo "Done.\n";
			}

			$domains = array(
				"portableapps.com" => true
			);

			$htmloptions = TagFilter::GetHTMLOptions();

			$web = new WebBrowser();

			$options = array(
				"headers" => array(
					"User-Agent" => "Wget/1.21.1"
				)
			);

			$url = "https://portableapps.com/updater/update.php";

			$result = $web->Process($url, $options);
			if (!$result["success"] || $result["response"]["code"] != 200)  CLI::DisplayError("An error occurred while retrieving update information.", $result);

			// Store the downloaded file locally.
			$filename = $config["storage_dir"] . "/update.7z";

			file_put_contents($filename, $result["body"]);
			@unlink($config["storage_dir"] . "/update_prev.ini");
			rename($config["storage_dir"] . "/update.ini", $config["storage_dir"] . "/update_prev.ini");

			// Extract the 'update.ini' file from the compressed archive.
			$os = php_uname("s");
			$windows = (strtoupper(substr($os, 0, 3)) == "WIN");

			$cmd = ($windows ? $rootpath . "/7za.exe" : ProcessHelper::FindExecutable("7za", "/usr/bin"));
			if ($cmd === false)  CLI::DisplayError("7za (7-Zip) is not available/installed.");
			$cmd .= " x " . escapeshellarg("-o" . $config["storage_dir"]) . " " . escapeshellarg($filename) . " update.ini";

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			if (!file_exists($config["storage_dir"] . "/update.ini"))  CLI::DisplayError("Unable to extract file.", $result2);

			@unlink($config["storage_dir"] . "/update_orig.ini");
			copy($config["storage_dir"] . "/update.ini", $config["storage_dir"] . "/update_orig.ini");

			// Now scan the INI file and extract the list of apps.
			$result = PAMP_LoadUpdateINI($config["storage_dir"] . "/update_orig.ini");
			if (!$result["success"])  CLI::DisplayError("Failed to load INI.", $result);

			$appmap = $result["appmap"];

			$verfilemap = @json_decode(file_get_contents($config["storage_dir"] . "/verfilemap.dat"), true);
			if (!is_array($verfilemap))  $verfilemap = array();

			function AppsSync_DownloadFileCallback($response, $data, $opts)
			{
				global $suppressoutput;

				if ($response["code"] == 200)
				{
					$size = ftell($opts);
					fwrite($opts, $data);

					if (!$suppressoutput && $size % 1000000 > ($size + strlen($data)) % 1000000)  echo ".";
				}

				return true;
			}

			$finalresult = array(
				"success" => true,
				"files" => array()
			);

			// Download updates.
			$appmap2 = array();
			$appkeys = array();
			foreach ($config["apps"] as $appkey)
			{
				if (!isset($appmap[$appkey]))  continue;

				$appkeys[$appkey] = true;
				$info = $appmap[$appkey];

				$info2 = array();
				foreach ($info as $key => $val)
				{
					if (substr($key, 0, 12) === "DownloadFile" || substr($key, 0, 4) === "Hash" || substr($key, 0, 11) === "UpdateFrom-")  continue;

					$info2[$key] = $val;
				}

				$failed = false;
				foreach ($config["langs"] as $lang)
				{
					if ($lang === "Default")
					{
						$filekey = "DownloadFile";
						$hashkey = "Hash";
					}
					else
					{
						$filekey = "DownloadFile_" . $lang;
						$hashkey = "Hash_" . $lang;
					}

					if (!isset($info[$filekey]))
					{
						$filekey = "DownloadFile";
						$hashkey = "Hash";
					}

					if (!isset($info[$filekey]))  continue;

					$info[$filekey] = str_replace(array("\\", "/"), "", $info[$filekey]);

					$info2[$filekey] = $info[$filekey];
					$info2[$hashkey] = (isset($info[$hashkey . "256"]) ? $info[$hashkey . "256"] : $info[$hashkey]);

					if (!isset($info2["DownloadFile"]))
					{
						$info2["DownloadFile"] = $info2[$filekey];
						$info2["Hash"] = $info2[$hashkey];
					}

					$path = sys_get_temp_dir();
					$path = str_replace("\\", "/", $path);
					if (substr($path, -1) !== "/")  $path .= "/";
					$path .= "pamp_temp_" . getmypid() . "_" . microtime(true);

					$tempfile = $path . "_" . $info[$filekey];

					if (!file_exists($config["storage_dir"] . "/" . $info[$filekey]))
					{
						$url = (isset($info["DownloadPath"]) ? $info["DownloadPath"] : "http://portableapps.com/redirect/?s=p&a=" . urlencode($appkey) . "&d=sfpa&f=");
						$url = str_replace("%WINDOWSVERSION%", "8.1", $url);
						$url .= urlencode($info[$filekey]);

						if (!$suppressoutput)  echo "Downloading '" . $info[$filekey] . "' (" . $info["DownloadSize"] . "MB)...";

						$fp = fopen($tempfile, "wb");

						$options["read_body_callback"] = "AppsSync_DownloadFileCallback";
						$options["read_body_callback_opts"] = $fp;

						$web = new WebBrowser();
						$result = $web->Process($url, $options);

						fclose($fp);

						if (!$suppressoutput)  echo "Done.\n";

						if (!$result["success"] || $result["response"]["code"] != 200)
						{
							CLI::DisplayError("An error occurred while downloading '" . $url . "' (" . $appkey . ").", $result, false);

							$failed = true;

							break;
						}
						else
						{
							// Handle SourceForge HTML.
							if (filesize($tempfile) < 2000000)
							{
								$data = file_get_contents($tempfile);
								$pos = strpos($data, "btn-problems-downloading");
								if ($pos !== false)
								{
									$html = TagFilter::Explode($data, $htmloptions);
									$root = $html->Get();
									$row = $root->Find('#btn-problems-downloading')->current();
									if ($row)
									{
										$url = $row->{"data-release-url"};

										if (!$suppressoutput)  echo "Downloading '" . $info[$filekey] . "' (SourceForge, " . $info["DownloadSize"] . "MB)...";

										$fp = fopen($tempfile, "wb");

										$options["read_body_callback"] = "AppsSync_DownloadFileCallback";
										$options["read_body_callback_opts"] = $fp;

										$web = new WebBrowser();
										$result = $web->Process($url, $options);

										fclose($fp);

										if (!$suppressoutput)  echo "Done.\n";
									}
								}
							}

							if (!$result["success"] || $result["response"]["code"] != 200)
							{
								CLI::DisplayError("An error occurred while downloading '" . $url . "' (" . $appkey . ").", $result, false);

								$failed = true;

								break;
							}
							else if (hash_file("md5", $tempfile) !== strtolower($info2[$hashkey]) && hash_file("sha256", $tempfile) !== strtolower($info2[$hashkey]))
							{
								CLI::DisplayError("An error occurred while downloading '" . $url . "' (" . $appkey . ").  The hash of the retrieved file does not match.", false, false);

								$failed = true;
							}
							else
							{
								if (!$suppressoutput)  echo "Copying to '" . $config["storage_dir"] . "/" . $info[$filekey] . "'...";

								copy($tempfile, $config["storage_dir"] . "/" . $info[$filekey]);

								if (!$suppressoutput)  echo "Done.\n";

								$finalresult["files"][] = $config["storage_dir"] . "/" . $info[$filekey];
							}
						}

						@unlink($tempfile);
					}

					// Process the file for an external download if the filename is a magic string.
					if (strtolower(substr($info[$filekey], -15)) === "_online.paf.exe" && file_exists($config["storage_dir"] . "/" . $info[$filekey]))
					{
						if (!$suppressoutput)  echo "Processing '" . $config["storage_dir"] . "/" . $info[$filekey] . "'...\n";

						require_once $rootpath . "/support/win_pe_file.php";

						$srcfile = $config["storage_dir"] . "/" . $info[$filekey];

						$result = WinPEFile::ValidateFile($srcfile, false);
						if (!$result["success"])  CLI::DisplayError("Unable to validate '" . $srcfile . "' as a valid executable file.", $result, false);
						else
						{
							// Load the file.
							$winpe = new WinPEFile();
							$winpe->Parse(file_get_contents($srcfile));

							// Extract the Version resource.
							require_once $rootpath . "/support/win_pe_utils.php";

							$result = WinPEUtils::GetVersionResource($winpe);
							if (!$result["success"])  CLI::DisplayError("Unable to extract the Version Info resource from '" . $srcfile . "'.", $result, false);
							else
							{
								$found = false;
								foreach ($result["entry"]["string_file_info"]["string_tables"] as $strs)
								{
									$pnum = 1;
									$onlinesha256key = "PortableApps.comDownloadSHA256";
									$onlineurlkey = "PortableApps.comDownloadURL";
									$onlineknockurlkey = "PortableApps.comDownloadKnockURL";

									$infoprefix = "Online_";
									$infourlprefix = "OnlineURL_";

									while (isset($strs[$onlinesha256key]) && isset($strs[$onlineurlkey]))
									{
										$url = $strs[$onlineurlkey];
										$url2 = HTTP::ExtractURL($url);

										$filename = hash("sha256", $url);

										if (($pos = strrpos($url, "%2F")) !== false)  $filename .= "." . substr($url, $pos + 3);
										else if (($pos = strrpos($url, "/")) !== false)  $filename .= "." . substr($url, $pos + 1);

										$domains[$url2["host"]] = true;

										// If a file has already been downloaded that matches the hash, then skip it.
										if (file_exists($config["storage_dir"] . "/" . $filename) && hash_file("sha256", $config["storage_dir"] . "/" . $filename) === strtolower($strs[$onlinesha256key]))
										{
											$info[$infoprefix . $filekey] = $filename;
											$info[$infourlprefix . $filekey] = $url;
											$info[$infoprefix . $hashkey] = $strs[$onlinesha256key];

											$found = true;
										}
										else
										{
											// Knock on the door to allow the download.  Supposedly not used though by any app.
											if (isset($strs[$onlineknockurlkey]) && substr($strs[$onlineknockurlkey], 0, 2) !== "\${")  // }
											{
												$web = new WebBrowser();
												$web->Process($strs[$onlineknockurlkey], $options);
											}

											// Download the file.
											if (!$suppressoutput)  echo "Downloading '" . $url . "' (" . $info["DownloadSize"] . "MB)...";

											$fp = fopen($tempfile, "wb");

											$options["read_body_callback"] = "AppsSync_DownloadFileCallback";
											$options["read_body_callback_opts"] = $fp;

											$web = new WebBrowser();
											$result2 = $web->Process($url, $options);

											fclose($fp);

											if (!$suppressoutput)  echo "Done.\n";

											if (!$result2["success"] || $result2["response"]["code"] != 200)
											{
												CLI::DisplayError("An error occurred while downloading '" . $url . "' (" . $appkey . ").", $result2, false);

												$failed = true;
											}
											else if (hash_file("sha256", $tempfile) !== strtolower($strs[$onlinesha256key]))
											{
												CLI::DisplayError("An error occurred while downloading '" . $url . "' (" . $appkey . ").  The hash of the retrieved file does not match.", false, false);

												$failed = true;
											}
											else
											{
												if (isset($verfilemap[$appkey]) && isset($verfilemap[$appkey][$infoprefix . $filekey]) && file_exists($config["storage_dir"] . "/" . $verfilemap[$appkey][$infoprefix . $filekey]))
												{
													if (!$suppressoutput)  echo "Deleting '" . $config["storage_dir"] . "/" . $verfilemap[$appkey][$infoprefix . $filekey] . "'.\n";

													@unlink($config["storage_dir"] . "/" . $verfilemap[$appkey][$infoprefix . $filekey]);
												}

												if (!$suppressoutput)  echo "Copying to '" . $config["storage_dir"] . "/" . $filename . "'...";

												copy($tempfile, $config["storage_dir"] . "/" . $filename);

												if (!$suppressoutput)  echo "Done.\n";

												$info[$infoprefix . $filekey] = $filename;
												$info[$infourlprefix . $filekey] = $url;
												$info[$infoprefix . $hashkey] = $strs[$onlinesha256key];

												$finalresult["files"][] = $config["storage_dir"] . "/" . $filename;

												$found = true;
											}
										}

										$pnum++;
										$onlinesha256key = "PortableApps.comDownload" . $pnum . "SHA256";
										$onlineurlkey = "PortableApps.comDownload" . $pnum . "URL";
										$onlineknockurlkey = "PortableApps.comDownload" . $pnum . "KnockURL";

										$infoprefix = "Online" . $pnum . "_";
										$infourlprefix = "OnlineURL" . $pnum . "_";
									}

									if ($found || $failed)  break;
								}

								if (!$found)
								{
									CLI::DisplayError("Didn't find a matching string table entry in the resources.", false, false);

									var_dump($result["entry"]["string_file_info"]["string_tables"]);
								}

								@unlink($tempfile);
							}
						}
					}

					$appmap2[$appkey] = $info2;
				}

				if (!$failed)
				{
					// Remove old files.
					if (isset($verfilemap[$appkey]) && $verfilemap[$appkey]["UpdateDate"] !== $info["UpdateDate"])
					{
						foreach ($verfilemap[$appkey] as $key => $val)
						{
							if (substr($key, 0, 12) === "DownloadFile")
							{
								if (!$suppressoutput)  echo "Deleting '" . $config["storage_dir"] . "/" . $val . "'.\n";

								@unlink($config["storage_dir"] . "/" . $val);
							}
						}
					}

					// Update the file mapping file.
					$verfilemap[$appkey] = $info;

					file_put_contents($config["storage_dir"] . "/verfilemap.dat", json_encode($verfilemap, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
				}
			}

			// Remove entries and files no longer being tracked.
			foreach ($verfilemap as $appkey => $info)
			{
				if (!isset($appkeys[$appkey]))
				{
					if (!$suppressoutput)  echo "Removing " . $appkey . " files.\n";

					foreach ($info as $key => $val)
					{
						if (substr($key, 0, 12) === "DownloadFile" || (substr($key, 0, 6) === "Online" && strpos($key, "DownloadFile") !== false))  @unlink($config["storage_dir"] . "/" . $val);
					}

					unset($verfilemap[$appkey]);

					file_put_contents($config["storage_dir"] . "/verfilemap.dat", json_encode($verfilemap, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
				}
			}


			// Generate a new INI file.
			$data = array();
			foreach ($appmap2 as $appkey => $info)
			{
				$data[] = "\n[" . $appkey . "]";

				foreach ($info as $key => $val)  $data[] = $key . "=" . $val;
			}

			file_put_contents($config["storage_dir"] . "/update.ini", trim(implode("\n", $data)) . "\n");

			// Generate a new 7z file for delivery.
			$filename = $config["storage_dir"] . "/update2.7z";
			@unlink($filename);

			$cmd = ($windows ? $rootpath . "/7za.exe" : ProcessHelper::FindExecutable("7za", "/usr/bin"));
			$cmd .= " a " . escapeshellarg($filename) . " " . escapeshellarg($config["storage_dir"] . "/update.ini");

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			if (!file_exists($filename))  CLI::DisplayError("Unable to create 7z file.", $result2);


			// Create and sign a SSL certificate for the list of domains.
			@unlink($rootpath . "/php-ssl-certs/cache/domains/domains_chain.pem");
			@unlink($rootpath . "/php-ssl-certs/certs/domains.json");
			if (!$suppressoutput)  echo "Creating MITM proxy domains certificate (this will probably take a bit)...";

			// Initialize.
			$cmd = $certscmd . " init domains -ca N";

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			if (!file_exists($rootpath . "/php-ssl-certs/certs/domains.json"))  CLI::DisplayError("Unable to create domains object.", $result2);
			$result3 = @json_decode($result2["stdout"], true);
			if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create domains object.", $result3);

			// Generate CSR and private key.
			$cmd = $certscmd . " csr domains -bits 4096 -digest sha256";
			foreach ($domains as $domain => $val)  $cmd .= " -domain " . escapeshellarg($domain);
			$cmd .= " -domain \"\" -keyusage \"\" -country \"\" -state \"\" -city \"\" -org \"\" -orgunit \"\" -email \"\" -commonname \"portableapps.com\"";

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			$result3 = @json_decode($result2["stdout"], true);
			if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to create domains CSR.", $result3);

			// Sign CSR.
			$cmd = $certscmd . " sign intermediate domains -ca N -days 365 -digest sha256 -bits 4096 -redo N";

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			$result3 = @json_decode($result2["stdout"], true);
			if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to sign domains CSR.", $result3);

			// Export certificate.
			$cmd = $certscmd . " export domains";

			$result = ProcessHelper::StartProcess($cmd);
			if (!$result)  CLI::DisplayError("Unable to start process.", $result);

			$result2 = ProcessHelper::Wait($result["proc"], $result["pipes"]);
			$result3 = @json_decode($result2["stdout"], true);
			if (!is_array($result3) || !$result3["success"])  CLI::DisplayError("Unable to export domains certificate chain.", $result3);

			if (!$suppressoutput)  echo "Done.\n";

			@unlink($config["storage_dir"] . "/proxy_root_cert.der");
			file_put_contents($config["storage_dir"] . "/proxy_root_cert.der", file_get_contents($rootpath . "/php-ssl-certs/cache/root/root_cert.der"));
			@chmod($config["storage_dir"] . "/proxy_root_cert.der", 0644);

			@unlink($config["storage_dir"] . "/proxy_root_cert.pem");
			file_put_contents($config["storage_dir"] . "/proxy_root_cert.pem", file_get_contents($rootpath . "/php-ssl-certs/cache/root/root_cert.pem"));
			@chmod($config["storage_dir"] . "/proxy_root_cert.pem", 0644);

			@mkdir($config["storage_dir"] . "/cert");

			file_put_contents($config["storage_dir"] . "/cert/domains_chain.pem", file_get_contents($rootpath . "/php-ssl-certs/cache/domains/domains_chain.pem"));
			@chmod($config["storage_dir"] . "/cert/domains_chain.pem", 0644);
			if (function_exists("posix_geteuid"))  @chgrp($config["storage_dir"] . "/cert/domains_chain.pem", "portable-apps-proxy");

			file_put_contents($config["storage_dir"] . "/cert/domains_private_key.pem", file_get_contents($rootpath . "/php-ssl-certs/cache/domains/domains_private_key.pem"));
			@chmod($config["storage_dir"] . "/cert/domains_private_key.pem", 0640);
			if (function_exists("posix_geteuid"))  @chgrp($config["storage_dir"] . "/cert/domains_private_key.pem", "portable-apps-proxy");

			touch($config["storage_dir"] . "/verfilemap.dat");

			CLI::DisplayResult($finalresult);
		}
		else if ($cmd === "list")
		{
			$result = array(
				"success" => true,
				"apps" => $config["apps"]
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "import-existing")
		{
			CLI::ReinitArgs($args, array("dir"));

			// Scan the INI file and extract the list of languages.
			if (!file_exists($config["storage_dir"] . "/update_orig.ini"))  CLI::DisplayError("Unable to perform task since the required update file is missing.  Run 'php config.php apps sync' first.");

			$result = PAMP_LoadUpdateINI($config["storage_dir"] . "/update_orig.ini");
			if (!$result["success"])  CLI::DisplayError("Failed to load INI.", $result);

			$appmap = $result["appmap"];

			do
			{
				$path = CLI::GetUserInputWithArgs($args, "dir", "Path", false, "Enter the full path to the directory containing your Portable Apps.", $suppressoutput);

				if (!is_dir($path))  CLI::DisplayError("The entered path is not a valid directory.", false, false);
				else if (!is_dir($path . "/PortableApps.com"))  CLI::DisplayError("The entered path does not contain Portable Apps.", false, false);
			} while (!is_dir($path));

			$dir = opendir($path);
			if ($dir)
			{
				$config["apps"] = array();

				while (($file = readdir($dir)) !== false)
				{
					if (is_dir($path . "/" . $file) && isset($appmap[$file]))  $config["apps"][] = $file;
				}

				closedir($dir);
			}

			sort($config["apps"], SORT_NATURAL | SORT_FLAG_CASE);
			$config["apps"] = array_values($config["apps"]);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "add")
		{
			CLI::ReinitArgs($args, array("find", "app"));

			// Scan the INI file and extract the list of languages.
			if (!file_exists($config["storage_dir"] . "/update_orig.ini"))  CLI::DisplayError("Unable to perform task since the required update file is missing.  Run 'php config.php apps sync' first.");

			$result = PAMP_LoadUpdateINI($config["storage_dir"] . "/update_orig.ini");
			if (!$result["success"])  CLI::DisplayError("Failed to load INI.", $result);

			$appmap = $result["appmap"];

			// Remove already added apps from the list.
			foreach ($config["apps"] as $appkey)
			{
				unset($appmap[$appkey]);
			}

			$findstr = CLI::GetUserInputWithArgs($args, "find", "Search terms", "", "Find an app by name, category, subcategory, and/or description.  Leave blank to select from all apps.", $suppressoutput);

			$appmap2 = array();
			foreach ($appmap as $appkey => $info)
			{
				if ($findstr === "" || stripos($info["Name"], $findstr) !== false || stripos($info["Description"], $findstr) !== false || stripos($info["Category"], $findstr) !== false || stripos($info["SubCategory"], $findstr) !== false)
				{
					$appmap2[$appkey] = $info;
				}
			}

			if (!count($appmap2))  CLI::DisplayError("No applications found.");

			function AppsAdd_AppsSort($info, $info2)
			{
				$result = strnatcasecmp($info["Category"], $info2["Category"]);
				if (!$result)  $result = strnatcasecmp($info["Name"], $info2["Name"]);

				return $result;
			}

			uasort($appmap2, "AppsAdd_AppsSort");

			$appmap3 = array();
			$categories = array();
			foreach ($appmap2 as $appkey => $info)
			{
				$appmap3[$appkey] = $info["Name"] . "\n\t" . $info["Description"] . "\n\t" . $info["Category"] . " -> " . $info["SubCategory"] . "\n";

				$categories["All " . $info["Category"]] = "Add all applications in " . $info["Category"];
			}

			// Add special options when the empty string is used.
			if ($findstr === "")
			{
				$appmap3["All"] = "Add all applications (aka full mirror)";

				ksort($categories);

				$appmap3 = $appmap3 + $categories;
			}

			$appkey = CLI::GetLimitedUserInputWithArgs($args, "app", "Application", false, "Available apps:", $appmap3, true, $suppressoutput);

			if ($appkey === "All")
			{
				foreach ($appmap2 as $appkey2 => $info)  $config["apps"][] = $appkey2;
			}
			else if (isset($categories[$appkey]))
			{
				$category = substr($appkey, 4);

				foreach ($appmap2 as $appkey2 => $info)
				{
					if ($info["Category"] === $category)  $config["apps"][] = $appkey2;
				}
			}
			else
			{
				$config["apps"][] = $appkey;
			}

			sort($config["apps"], SORT_NATURAL | SORT_FLAG_CASE);
			$config["apps"] = array_values($config["apps"]);

			SaveConfig();

			$result = array(
				"success" => true,
				"app" => $appkey
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			CLI::ReinitArgs($args, array("app"));

			if (!count($config["apps"]))  CLI::DisplayError("No applications found.");

			$num = CLI::GetLimitedUserInputWithArgs($args, "app", "Application", false, "Available apps:", $config["apps"], true, $suppressoutput);

			array_splice($config["apps"], $num, 1);

			SaveConfig();

			$result = array(
				"success" => true
			);

			CLI::DisplayResult($result);
		}
	}
	else if ($cmdgroup === "service")
	{
		if ($cmd === "install")
		{
			// Verify root on *NIX.
			if (function_exists("posix_geteuid"))
			{
				$uid = posix_geteuid();
				if ($uid !== 0)  CLI::DisplayError("The installer must be run as the 'root' user (UID = 0) to install the system service on *NIX hosts.");

				// Create the system user/group.
				ob_start();
				system("useradd -r -s /bin/false " . escapeshellarg("portable-apps-proxy"));
				$output = ob_get_contents() . "\n";
				ob_end_clean();
			}

			// Make sure the configuration is readable by the user.
			SaveConfig();

			if (function_exists("posix_geteuid"))  @chgrp($rootpath . "/config.dat", "portable-apps-proxy");

			// Install the system service.
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " install";

			ob_start();
			system($cmd);
			$output .= ob_get_contents();
			ob_end_clean();

			$result = array(
				"success" => true,
				"output" => $output
			);

			CLI::DisplayResult($result);
		}
		else if ($cmd === "remove")
		{
			$cmd = escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " uninstall";

			ob_start();
			system($cmd);
			$output = ob_get_contents();
			ob_end_clean();

			$result = array(
				"success" => true,
				"output" => $output
			);

			CLI::DisplayResult($result);
		}
	}
?>