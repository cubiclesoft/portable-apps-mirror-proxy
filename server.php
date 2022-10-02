<?php
	// Portable Apps mirror/proxy server.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/pamp_functions.php";
	require_once $rootpath . "/support/web_server.php";
	require_once $rootpath . "/support/cli.php";

	// Load configuration.
	$config = PAMP_LoadConfig();

	if (!file_exists($config["storage_dir"] . "/update2.7z"))  CLI::DisplayError("Unable to perform task since the required update file is missing.  Run 'php config.php apps sync' first.");

	if ($argc > 1)
	{
		// Service Manager PHP SDK.
		require_once $rootpath . "/support/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = "php-portable-apps-proxy";

		if ($argv[1] == "install")
		{
			// Install the service.
			$args = array();
			$options = array(
				"nixuser" => "portable-apps-proxy",
				"nixgroup" => "portable-apps-proxy"
			);

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "start")
		{
			// Start the service.
			$result = $sm->Start($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to start the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "stop")
		{
			// Stop the service.
			$result = $sm->Stop($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to stop the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "dumpconfig")
		{
			$result = $sm->GetConfig($servicename);
			if (!$result["success"])  CLI::DisplayError("Unable to retrieve the configuration for the '" . $servicename . "' service.", $result);

			echo "Service configuration:  " . $result["filename"] . "\n\n";

			echo "Current service configuration:\n\n";
			foreach ($result["options"] as $key => $val)  echo "  " . $key . " = " . $val . "\n";
		}
		else
		{
			echo "Command not recognized.  Run the service manager directly for anything other than 'install', 'start', 'stop', 'uninstall', and 'dumpconfig'.\n";
		}

		exit();
	}

	$accessfpnum = 0;
	$accessfp = false;
	function WriteAccessLog($trace, $ipaddr, $request, $stats, $info)
	{
		global $accessfp, $accessfpnum;

		$accessfpnum++;
		if ($accessfp !== false)
		{
			fwrite($accessfp, json_encode(array("#" => $accessfpnum, "ts" => time(), "gmt" => gmdate("Y-m-d H:i:s"), "trace" => $trace, "ip" => $ipaddr, "req" => $request, "stats" => $stats, "info" => $info), JSON_UNESCAPED_SLASHES) . "\n");
			fflush($accessfp);
		}

		echo $trace . " - ";
		if (is_string($request))  echo $request;
		else if (isset($request["line"]))  echo $request["line"];
		else  echo json_encode($request, JSON_UNESCAPED_SLASHES);
		echo "\n";

		echo "\tReceived " . number_format($stats["rawrecv"], 0) . " bytes; Sent " . number_format($stats["rawsend"], 0) . " bytes\n";
		echo "\t" . json_encode($info, JSON_UNESCAPED_SLASHES) . "\n";
	}

	function SendHTTPErrorResponse($client)
	{
		// Reset the response headers.
		if (!$client->responsefinalized)
		{
			$client->responseheaders = array();
			$client->responsebodysize = true;

			$client->SetResponseContentType("text/html; charset=UTF-8");
		}

		$client->SetResponseCode($client->appdata["respcode"]);

		// Prevent browsers and proxies from doing bad things.
		$client->SetResponseNoCache();

		$client->AddResponseContent($client->appdata["respcode"] . " " . $client->appdata["respmsg"]);
		$client->FinalizeResponse();
	}

	function InitClientAppData()
	{
		return array("url" => false, "file" => false, "respcode" => 200, "respmsg" => "OK");
	}

	class StaticFileWebServer extends WebServer
	{
		protected function HandleResponseCompleted($id, $result)
		{
			$client = $this->GetClient($id);
			if ($client === false || $client->appdata === false)  return;

			if ($client->appdata["file"] !== false)  $handler = "static";
			else  $handler = "other/none";

			$stats = array(
				"rawrecv" => ($result["success"] ? $result["rawrecvsize"] : $client->httpstate["result"]["rawrecvsize"]),
				"rawrecvhead" => ($result["success"] ? $result["rawrecvheadersize"] : $client->httpstate["result"]["rawrecvheadersize"]),
				"rawsend" => ($result["success"] ? $result["rawsendsize"] : $client->httpstate["result"]["rawsendsize"]),
				"rawsendhead" => ($result["success"] ? $result["rawsendheadersize"] : $client->httpstate["result"]["rawsendheadersize"]),
			);

			$info = array(
				"handler" => $handler,
				"code" => $client->appdata["respcode"],
				"msg" => $client->appdata["respmsg"]
			);

			WriteAccessLog("WebServer:" . $id, $client->ipaddr, $client->request, $stats, $info);

			if ($client->appdata["file"] !== false && isset($client->appdata["file"]["fp"]) && $client->appdata["file"]["fp"] !== false)  fclose($client->appdata["file"]["fp"]);

			$client->appdata = InitClientAppData();
		}
	}

	$webserver = new StaticFileWebServer();

	// Enable writing files to the system.
	$cachedir = WebServer::MakeTempDir("php_portable_apps_proxy");
	$webserver->SetCacheDir($cachedir);

	// Open an access log file.
	$path = sys_get_temp_dir();
	$path = str_replace("\\", "/", $path);
	if (substr($path, -1) !== "/")  $path .= "/";

	$filename = $path . "php_portable_apps_proxy_access.log";
	if (file_exists($filename) && filesize($filename) > 10000000)  @unlink($filename);
	$accessfp = fopen($filename, "ab");

	// Enable longer active client times.
	$webserver->SetDefaultClientTimeout(300);
	$webserver->SetMaxRequests(200);

	$mimetypemap = array(
		"" => "text/html",
		"exe" => "application/octet-stream",
		"7z" => "application/octet-stream",
		"zip" => "application/octet-stream",
		"pem" => "application/x-x509-ca-cert",
		"der" => "application/x-x509-ca-cert"
	);

	// Load storage directory information.
	$lastverfilets = 0;
	function ReloadStorageDirInfo()
	{
		global $config, $filesavail, $lastverfilets, $mimetypemap;

		clearstatcache();

		$ts = filemtime($config["storage_dir"] . "/verfilemap.dat");
		if ($lastverfilets < $ts)
		{
			if ($lastverfilets)  echo "Reloaded storage directory.\n";

			$lastverfilets = $ts;

			$filesavail = array();
			$dir = opendir($config["storage_dir"]);
			if ($dir)
			{
				while (($file = readdir($dir)) !== false)
				{
					$pos = strrpos($file, ".");
					if ($pos !== false)
					{
						$ext = strtolower(substr($file, $pos + 1));

						if (isset($mimetypemap[$ext]))  $filesavail[$file] = true;
					}
				}

				closedir();
			}
		}
	}

	ReloadStorageDirInfo();

	echo "Starting server " . $config["server_ip"] . ":" . $config["server_port"] . "...\n";
	$result = $webserver->Start($config["server_ip"], $config["server_port"], false);
	if (!$result["success"])
	{
		var_dump($result);

		exit();
	}

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		// Implement the stream_select() call directly since async clients are involved.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		$webserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)  break;

		$result = $webserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if ($client->appdata === false)
			{
				echo "Client ID " . $id . " connected.\n";

				$client->appdata = InitClientAppData();
			}

			if ($client->requestcomplete)
			{
				if ($client->appdata["url"] === false)
				{
					if ($client->request["method"] === "CONNECT")
					{
						$client->appdata["url"] = HTTP::ExtractURL("proto://" . $client->url);

						$client->keepalive = true;

						// Hardcode the beginning of the response.
						$client->httpstate["data"] = $client->request["httpver"] . " 200 Connection Established\r\n";

						// Reset the response headers.
						if (!$client->responsefinalized)
						{
							$client->responseheaders = array();
							$client->responsebodysize = false;
						}

						// Not entirely sure why "Connection: close" is sent on a keep-alive connection.
						$client->AddResponseHeader("Connection", "close", true);

						$client->FinalizeResponse();

						// Enable SSL/TLS on the connection.
						if ($client->appdata["url"]["port"] == 443)
						{
							$client->ssl = "start";

							stream_context_set_option($client->fp, "ssl", "ciphers", HTTP::GetSSLCiphers());
							stream_context_set_option($client->fp, "ssl", "disable_compression", true);
							stream_context_set_option($client->fp, "ssl", "allow_self_signed", true);
							stream_context_set_option($client->fp, "ssl", "verify_peer", false);
							stream_context_set_option($client->fp, "ssl", "local_cert", $config["storage_dir"] . "/cert/domains_chain.pem");
							stream_context_set_option($client->fp, "ssl", "local_pk", $config["storage_dir"] . "/cert/domains_private_key.pem");
						}

						continue;
					}
					else
					{
						$client->appdata["url"] = HTTP::ExtractURL($client->url);

						// Check the request for the update data blob request vs. file download.
						$missing = false;
						if ($client->appdata["url"]["host"] === "portableapps.com" && $client->appdata["url"]["path"] === "/updater/update.php")  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/update2.7z");
						else if (isset($client->appdata["url"]["queryvars"]["f"]) && isset($filesavail[$client->appdata["url"]["queryvars"]["f"][0]]))  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/" . $client->appdata["url"]["queryvars"]["f"][0]);
						else if (($pos = strrpos($client->url, "%2F")) !== false && isset($filesavail[substr($client->url, $pos + 3)]))  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/" . substr($client->url, $pos + 3));
						else if (($pos = strrpos($client->url, "/")) !== false && isset($filesavail[substr($client->url, $pos + 1)]))  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/" . substr($client->url, $pos + 1));
						else if (($pos = strrpos($client->url, "%2F")) !== false && isset($filesavail[hash("md5", $client->url) . "." . substr($client->url, $pos + 3)]))  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/" . hash("md5", $client->url) . "." . substr($client->url, $pos + 3));
						else if (($pos = strrpos($client->url, "/")) !== false && isset($filesavail[hash("md5", $client->url) . "." . substr($client->url, $pos + 1)]))  $client->appdata["file"] = array("name" => $config["storage_dir"] . "/" . hash("md5", $client->url) . "." . substr($client->url, $pos + 1));
					}
				}

				// Code swiped from PHP App Server.
				if ($client->appdata["file"] !== false)
				{
					if (!isset($client->appdata["file"]["fp"]) && file_exists($client->appdata["file"]["name"]))
					{
						$filename = $client->appdata["file"]["name"];
						$client->appdata["file"]["ts"] = filemtime($filename);

						if (isset($client->headers["If-Modified-Since"]) && HTTP::GetDateTimestamp($client->headers["If-Modified-Since"]) === $client->appdata["file"]["ts"])
						{
							$client->appdata["respcode"] = 304;
							$client->appdata["respmsg"] = "Not Modified";

							unset($client->responseheaders["Content-Type"]);

							$client->SetResponseCode(304);
							$client->FinalizeResponse();
						}
						else
						{
							$client->appdata["file"]["fp"] = fopen($filename, "rb");
							if ($client->appdata["file"]["fp"] === false)
							{
								$client->appdata["respcode"] = 403;
								$client->appdata["respmsg"] = "Forbidden<br><br>Unable to open file for reading.";

								SendHTTPErrorResponse($client);
							}
							else
							{
								$size = filesize($filename);

								// Code swiped from the Barebones CMS PHP SDK.
								// Calculate the amount of data to transfer.  Only implement partial support for the Range header (coalesce requests into a single range).
								$start = 0;
								if (isset($client->headers["Range"]) && $size > 0)
								{
									$min = false;
									$max = false;
									$ranges = explode(";", $client->headers["Range"]);
									foreach ($ranges as $range)
									{
										$range = explode("=", trim($range));
										if (count($range) > 1 && strtolower($range[0]) === "bytes")
										{
											$chunks = explode(",", $range[1]);
											foreach ($chunks as $chunk)
											{
												$chunk = explode("-", trim($chunk));
												if (count($chunk) == 2)
												{
													$pos = trim($chunk[0]);
													$pos2 = trim($chunk[1]);

													if ($pos === "" && $pos2 === "")
													{
														// Ignore invalid range.
													}
													else if ($pos === "")
													{
														if ($min === false || $min > $size - (int)$pos)  $min = $size - (int)$pos;
													}
													else if ($pos2 === "")
													{
														if ($min === false || $min > (int)$pos)  $min = (int)$pos;
													}
													else
													{
														if ($min === false || $min > (int)$pos)  $min = (int)$pos;
														if ($max === false || $max < (int)$pos2)  $max = (int)$pos2;
													}
												}
											}
										}
									}

									// Normalize and cap byte ranges.
									if ($min === false)  $min = 0;
									if ($max === false)  $max = $size - 1;
									if ($min < 0)  $min = 0;
									if ($min > $size - 1)  $min = $size - 1;
									if ($max < 0)  $max = 0;
									if ($max > $size - 1)  $max = $size - 1;
									if ($max < $min)  $max = $min;

									// Translate to start and size.
									$start = $min;
									$size = $max - $min + 1;
								}

								$client->appdata["file"]["size"] = $size;

								if ($start)  fseek($client->appdata["file"]["fp"], $start);

								// Set various headers.
								$pos = strrpos($filename, ".");
								$ext = ($pos === false ? "" : strtolower(substr($filename, $pos + 1)));

								if (isset($mimetypemap[$ext]))  $client->SetResponseContentType($mimetypemap[$ext]);
								else  unset($client->responseheaders["Content-Type"]);

								$client->AddResponseHeader("Accept-Ranges", "bytes");

								$client->AddResponseHeader("Last-Modified", gmdate("D, d M Y H:i:s", $client->appdata["file"]["ts"]) . " GMT");
								$client->SetResponseContentLength($client->appdata["file"]["size"]);

								// Read first chunk of data.
								$data = fread($client->appdata["file"]["fp"], ($client->appdata["file"]["size"] >= 262144 ? 262144 : $client->appdata["file"]["size"]));

								$client->AddResponseContent($data);
								$client->appdata["file"]["size"] -= strlen($data);

								if (!$client->appdata["file"]["size"])  $client->FinalizeResponse();
							}
						}
					}
					else
					{
						// Continue reading data.
						$data = fread($client->appdata["file"]["fp"], ($client->appdata["file"]["size"] >= 262144 ? 262144 : $client->appdata["file"]["size"]));

						$client->AddResponseContent($data);
						$client->appdata["file"]["size"] -= strlen($data);

						if (!$client->appdata["file"]["size"])  $client->FinalizeResponse();
					}
				}
				else
				{
					$client->appdata["respcode"] = 404;
					$client->appdata["respmsg"] = "File Not Found";

					SendHTTPErrorResponse($client);
				}
			}
		}

		foreach ($result["removed"] as $id => $result2)
		{
			if ($result2["client"]->appdata !== false)
			{
				echo "Client ID " . $id . " disconnected.\n";

//				echo "Client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";
			}
		}

		$ts = time();
		if ($lastservicecheck <= $ts - 3)
		{
			ReloadStorageDirInfo();

			// Cleanup RAM.
			if (function_exists("gc_mem_caches"))  gc_mem_caches();

			// Check the status of the two service file options for correct Service Manager integration.
			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename))
			{
				// Reload configuration.
				echo "Reload requested.\n";

				$running = false;
			}

			$lastservicecheck = $ts;
		}
	} while ($running);
?>