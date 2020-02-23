<?php
require('autoloader.php');
require_once('context_loader.php');
require_once('outputert.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppDnssecUpdateDomainRequest;
use Metaregistrar\EPP\eppException;

if ($argc <= 1 || $argc > 2) {
    echo "Usage: signdomaindomain.php <domainname>\n";
	echo "<domainname>: Please enter the domain name to be DNSSEC'ed".PHP_EOL;
	// echo "<targetOrganization>: For example: enecogroup_oxxio".PHP_EOL;
    die();
}

$domainname = $argv[1];
echo "Domain: ".$domainname.PHP_EOL;
// $targetOrganization = $argv[2];
// echo "Target organization: ".$targetOrganization.PHP_EOL;

try {
    // Please enter your own settings file here under before using this example
	if ($conn = eppConnection::create(getSettingsFileByTld($domainname), true)) {
        $conn->enableDnssec();
        if ($conn->login()) {
            $add = new eppDomain($domainname);

			// First retrieve info of the domain
			$info = new eppInfoDomainRequest($add);
			if ($infoResponse = $conn->request($info)) {

				if($infoResponse->hasDnsSec()) {
					$keyCounter = $infoResponse->keyCount();
					echo " DNSSEC keys already configured. Key count: ".$keyCounter.PHP_EOL;
					echo "  Doing nothing. Exit.".PHP_EOL;
					die();
				} else {
					echo " DNSSEC not (yet) configured".PHP_EOL;
				}

				$dnssecValues = import($conn, $domainname);

				if(empty($dnssecValues)) {
					echo " DNSFILE not found in /dnssec folder";
				}

				$dnssecFlags	= $dnssecValues->flags;
				$dnssecAlg		= $dnssecValues->algorithm;
				$dnssecPubKey	= $dnssecValues->publicKey;
				$dnssecKeyId	= $dnssecValues->keytag;

				$sec = new eppSecdns();
				$sec->setKey($dnssecFlags, $dnssecAlg, $dnssecPubKey);
				$add->addSecdns($sec);

				// var_dump($sec);

				$update = new eppDnssecUpdateDomainRequest($domainname, $add);
				if ($response = $conn->request($update)) {
				    /* @var $response Metaregistrar\EPP\eppUpdateDomainResponse */
				    echo " DNSSEC added".PHP_EOL;

					// Move DNSSEC file into archive folder
					moveDnsSecFilename($domainname);
					echo "  (imported file moved to archive)".PHP_EOL;

					if($refreshedInfoResponse = $conn->request($info)) {

						if($refreshedInfoResponse->hasDnsSec()) {
							$dnssecData = $refreshedInfoResponse->getKeys();

							echo " DNSSEC keys: " . count($dnssecData) .PHP_EOL;

							foreach ($dnssecData as $secdns) {
							    // var_dump($secdns);
								echo "  >  " . substr($secdns->getPubkey(), 0, 25) . " [..] " . PHP_EOL;
							}
						}
					}
				}
			}
            $conn->logout();
        }
    }
} catch (eppException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

/**
 * @param $domainname string
 */
function getDnsSecFilename($domainname) {
	$dnsFilename = getDnsSecFolder().$domainname.".txt";

	return $dnsFilename;
}

function getDnsSecFolder() {
	$dnsSecFolder = "/../dnssec/";

	return $dnsSecFolder;
}

/**
 * @param $domainname string
 */
function moveDnsSecFilename($domainname) {
	$existingFilename = __DIR__ . getDnsSecFolder() . $domainname . ".txt";
	$newFilename = __DIR__ . getDnsSecFolder() . "completed/" . $domainname . ".txt";

	rename($existingFilename, $newFilename);

	// echo $existingFilename.PHP_EOL;
	// echo $newFilename.PHP_EOL;
}

/**
 * @param $conn eppConnection
 * @param $domainname string
 */
function import($conn, $domainname) {
	$dnsFilename = getDnsSecFilename($domainname);

	if(!file_exists(__DIR__ . $dnsFilename)) {
		echo " File not found! " . $dnsFilename .PHP_EOL;
		die();
	}

	$dnsData = file_get_contents(__DIR__ . $dnsFilename, true);

	$elements = explode(" ", $dnsData);

	$object = (object) [
		'domainname'=> rtrim(reset($elements), "."),
		'flags'		=> $elements[4],
		'algorithm'	=> $elements[6],
		'publicKey' => ltrim(rtrim($elements[7], ")"), "("), // Brackets are not compatible with EPP :(
		'keytag'	=> rtrim(end($elements))
		];
	echo " DNSSEC data imported OK".PHP_EOL;
	
	// var_dump($object);
	// echo $domainname.PHP_EOL;
	// echo $object->domainname.PHP_EOL;

	if(strcasecmp($domainname, $object->domainname) != 0) {
		echo "DNSSEC file contents contains values for wrong domain!".PHP_EOL;
		die();
	}

	return $object;
}