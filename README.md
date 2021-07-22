PHP-based Portable Apps Mirror/Proxy Server
===========================================

An unofficial mirror/proxy server with system service integration for the [Portable Apps platform](https://portableapps.com/) client software installer/updater.  Choose from a MIT or LGPL license and then combine the chosen license with the DISCLAIMER below.

This is similar to Windows Server Update Services (WSUS) but for the Portable Apps platform.  Ideally runs on an always-on computer.

DISCLAIMER:  This software is not produced by nor endorsed by Rare Ideas, LLC nor the development team behind the PortableApps.com(R) platform.  PortableApps.com(R) is [a registered trademark of Rare Ideas, LLC](https://portableapps.com/about/copyrights_and_trademarks) and all rights of that trademark belong to the rights holder.  The phrase "Portable Apps" is not to be construed as nor confused with as using the PortableApps.com(R) trademark by users of this software and its accompanying documentation.  Other than this clarifying legal disclaimer, you subsequently agree that no usage of the aforementioned registered trademark is performed by the software and its accompanying documentation.

![Mirror/Proxy server configuration/activity screenshot](https://user-images.githubusercontent.com/1432111/126655761-86acadea-214b-4a8e-b53c-725478b68bb7.png)

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Features
--------

* Update multiple machines running Portable Apps using your own local mirror/proxy service.
* This software runs equally well on Windows, Mac, and Linux.
* Simple configuration and synchronization.
* Selectively control what apps appear for installation/update within the Portable Apps client software.
* Ultra fast app updates over LANs.  No more waiting for hours for large downloads to complete in the client software.
* Enables the Portable Apps client software to even function offline - e.g. sneakernet updates from a mirror to an offline mirror.
* Handles inbound HTTPS requests as a psuedo-SSL/TLS CONNECT proxy.
* Has a liberal open source license.  MIT or LGPL, your choice.  Chosen license must be combined with the DISCLAIMER above.
* Designed for relatively painless integration into your project.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started on Windows
--------------------------

The following instructions assume that the server will run on Windows.  The server portion should be installed on a machine that is generally turned on so that updates can happen in the background on a schedule that you set.

If you want to change the configuration from the defaults (e.g. to run the service on a different port than 15644), then run `config.bat` before doing anything else.

Run `apps_sync.bat` to perform the first synchronization.  This will take a little time since it generates a few SSL/TLS certificates for the MITM proxy and downloads the ~5MB Portable Apps client software by default.

Run `config.bat` as many times as needed to configure the languages and apps to mirror.  The `apps import-existing` option can bulk select already installed apps, which is useful if the mirror/proxy software is being run on the same machine it will be used on and/or will share files with other machines on the network.

Run `apps_sync.bat` to perform the second synchronization.  This will take a while since it downloads all of the apps that were just added to the list of apps to mirror.

Run `run_server.bat` to start the main server in debug mode.  Windows Firewall will ask about allowing PHP to access the network.

![CertMgr screenshot showing the installed MITM proxy certificate](https://user-images.githubusercontent.com/1432111/126655773-e0ba9785-1764-4b98-b9b9-38f629520439.png)

Visit `http://IPADDRESS:15644/proxy_root_cert.der` in a web browser (replacing `IPADDRESS` with the IP address of the computer to connect to).  When asked, open the file and then click "Install certificate..." to install the generated [MITM proxy server](https://en.wikipedia.org/wiki/Man-in-the-middle_attack) CA certificate as a "Trusted Root Certificate Authority" on the "Local Machine".  Note that Administrator is required to complete this step.  The installed certificate should appear in CertMgr as seen above.

In the Portable Apps client software "Options" dialog, locate the "Connection" tab.  Select "Manual Proxy Configuration" and point it at the main server's IP address and port.

Perform an "Apps -> Check for Updates" operation in the Portable Apps client software.  The updater should behave normally without errors and the main server window should show activity.  If your Portable Apps installation is out of date, the software should offer to self-update to the latest version.

Stop debugging the main server at any time by pressing Ctrl + C.

To install the mirror/proxy to start at boot, right-click on `config.bat`, select "Run as administrator", and use the `service install` route.  Then right-click on `start_service.bat` and select "Run as administrator" to start the newly installed system service.

In Windows Task Scheduler, add a new item that runs `php-win\php.exe config.php apps sync` with the starting directory being the directory you put this software.  Set the run time to be on a sensible schedule (e.g. once a week at 2 am).

Getting Started on Mac/Linux
----------------------------

The following instructions assume the use of a Terminal or command-line console window of some sort (e.g. SSH).

Mac OSX comes with PHP pre-installed on the system.  On Linux, you will need to to install PHP if it isn't already installed (e.g. `sudo apt-get install php-cli` on Ubuntu/Debian).

Next, you need to install `7za` to handle the 7-Zip archives that this software needs to read and create.  On Mac OSX, 7-Zip is located in Homebrew/MacPorts in the `p7zip` package.  On Ubuntu/Debian Linux distros, 7-Zip is located in the `p7zip-full` package:

```
sudo apt-get install p7zip-full
```

If you want to change the configuration from the defaults (e.g. to run the service on a different port than 15644), then run `php config.php config` before doing anything else.  Don't forget to adjust any firewall settings.

Run `php config.php apps sync` to perform the first synchronization.  This will take a little time since it generates MITM proxy SSL/TLS certificates and downloads the ~5MB Portable Apps client software by default.

Run `php config.php` as many times as needed to configure the languages and apps to mirror.

Run `php config.php apps sync` to perform the second synchronization.  This will take a while since it downloads all of the apps that were just added to the list of apps to mirror.

Run `php server.php` to start the main server in debug mode.

Visit `http://IPADDRESS:15644/proxy_root_cert.der` in a web browser (replacing `IPADDRESS` with the IP address of the computer to connect to).  Download/Copy the file to each Windows computer.  On Windows, open the file and click "Install certificate..." to install the generated [MITM proxy server](https://en.wikipedia.org/wiki/Man-in-the-middle_attack) CA certificate as a "Trusted Root Certificate Authority" on the "Local Machine".  Note that Administrator is required to complete this step.  The certificate is also available at `data/proxy_root_cert.der` in case copying via SFTP is preferred.

On a Windows computer, in the Portable Apps client software "Options" dialog, locate the "Connection" tab.  Select "Manual Proxy Configuration" and point it at the main server's IP address and port.

Perform an "Apps -> Check for Updates" operation in the Portable Apps client software.  The updater should behave normally and the main server window should show activity.  If your Portable Apps installation is out of date, the software should offer to self-update to the latest version.

Stop debugging the main server at any time by pressing Ctrl + C.

To install the mirror/proxy to start at boot, run `sudo php config.php service install`.  Then start the `php-portable-apps-proxy` service normally (e.g. `service php-portable-apps-proxy start` on Ubuntu/Debian distros).

Finally, set up a new cron/crontab item that runs `/usr/bin/php /path/to/config.php apps sync` as root (i.e. `sudo crontab -e`).  Set the run time to be on a sensible schedule (e.g. once a week at 2 am).

How It Works
------------

First off, this software is not a true HTTP proxy.  It fakes responses for very specific requests.  As a result, it is able to deliver content for only the Portable Apps client software.  If you need a general-purpose proxy server, look elsewhere (HAProxy, Varnish, Nginx, etc).

What this software does do is deliver locally stored content as fast as possible.  This makes it ideal for use on LANs and/or in environments where Portable Apps are used extensively on multiple machines.  The public Portable Apps and SourceForge servers are rather slow at transferring content at ~150KB/sec while most other websites download at 10MB/sec or more.  But nothing beats gigabit LAN content delivery at up to 110MB/sec which is anywhere from 200 to 900 times faster.

The main server is only capable of delivering EXE, 7z, and ZIP files that have been stored in the configured data storage directory.  That's it.  It just delivers static files that have been stored in advance.  The files get there by running `php config.php apps sync`.  The 'apps sync' mechanism is what downloads the files into the local mirror.  The server automatically detects changes in the directory and makes the files available to connecting clients.

As an added bonus, 'apps sync' also builds a custom `update.ini` file that only contains mirrored applications.  Users who run "Apps -> Install a New App" in the client software just see a list of what is available on the mirror.  This eliminates application errors in the client during app install/updates but also has the side effect of providing refined control over what is readily available to users.  Obviously, users can still manually download apps directly from the Portable Apps website, so this "feature" is merely a deterrent.

When a HTTPS request is proxied, the Portable Apps client software attempts to establish a CONNECT HTTP proxy connection to the target domain.  This software intentionally performs a [man-in-the-middle (MITM) attack](https://en.wikipedia.org/wiki/Man-in-the-middle_attack) for the target domain so that the actual request can be handled by the local system instead.  The generated root CA certificate (created during the first 'apps sync') must therefore be installed into the Windows Trusted Root Certificate Store in order for CONNECT requests to succeed.  A better solution would be if the Portable Apps client software had, in addition to the manual proxy, an API option where a URL could be plugged in that becomes the prefix to the actual URL.  Then the MITM proxy portion wouldn't be needed and the rest of the server could be simplified.  Obviously that doesn't exist yet, so a MITM proxy is necessary.

Troubleshooting
---------------

Use the debugging mode of the server to watch the status of connections into the server.  That alone may provide a clue as to what is wrong.

Check the firewall rules between the server and the client.  If the server is running on Windows and other computers need to connect in, the Windows firewall on the server may be improperly configured.  This is especially true when a Windows PC joins a WiFi network for the first time, it will ask if it is on a Public vs. Private network and will default to Public, which blocks inbound connections.  If the server connects over WiFi and Public network was used, it will block all inbound connections by default.  Disconnect from the network, right-click on and forget the network, and then reconnect and be sure to select Private network.

The most common source of unusual errors in the Portable Apps client itself on Windows is with the MITM SSL/TLS proxy.  The generated root CA certificate must be installed into the Windows Trusted Root Certificate Store in order for CONNECT requests to succeed.  If there is two connections followed by a CONNECT request and then the client shows an error, that is the client attempting to upgrade to SSL/TLS and failing due to not being able to establish a SSL/TLS connection.

If the server debugging mode works but the system service causes errors to occur for certain applications (notably Google Chrome), then the SSL/TLS MITM certificate/private key on the server side probably have the wrong permissions and can't read the MITM certificate/private key files.  Happens primarily on Mac/Linux.  Run `sudo php config.php apps sync` one time to correctly set permissions/groups and try again.

More Information
----------------

If you plan on running a full mirror of all 450+ apps or a significant percentage thereof, then you should probably first [become a Portable Apps project sponsor](https://portableapps.com/donate/sponsor) and also ask and get permission via the [Portable Apps forums](https://portableapps.com/forums/).  Otherwise, you risk getting IP banned for abuse of system resources either by the Portable Apps team or SourceForge (or both).  In general, use this software responsibly by selecting just the apps you actually use in an effort to reduce your own bandwidth usage.

Due to the included MITM proxy and licensing restrictions of some Portable Apps (e.g. Google Chrome), this software is not recommended for use as a public, Internet-facing mirror.  It is intended for use on LANs and VPNs only.

PHP for Windows is bundled with this software for purposes of user convenience on Windows.  PHP is licensed under the [PHP License](https://www.php.net/license/index.php).
