<?php
require('autoloader.php');
require_once('context_loader.php');
require_once('outputert.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppInfoDomainResponse;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppDnssecUpdateDomainRequest;
use Metaregistrar\EPP\eppException;

abstract class DnssecAction
{
    const Remove = 0;
	const Add = 1;
	const Update = 2;
}

if ($argc <= 1 || $argc > 3) {
    echo "Usage: signdomaindomain.php <domainname> [<action>]".PHP_EOL;
	echo "<domainname>: Please enter the domain name to be DNSSEC'ed".PHP_EOL;
	echo "<actions>: Optionally instruct what to do (default Add)".PHP_EOL;
	echo " --add: adds DNSSEC configuration only when not signed yet.".PHP_EOL;
	echo " --remove: removes the DNSSEC configuration.".PHP_EOL;
	echo " --update: removes existing and (re)add new DNSSEC configuration.".PHP_EOL;
	// echo "<targetOrganization>: For example: enecogroup_oxxio".PHP_EOL;
    die();
}

$domainname = $argv[1];
echo "Domain: ".$domainname.PHP_EOL;
$action = DnssecAction::Add;
if($argc == 3 && $argv[2]) {
	if(strcasecmp($argv[2], "--remove") == 0) {
		$action = DnssecAction::Remove;
		echo "DNSSEC: will be REMOVED (in case configured).".PHP_EOL;
	} else if (strcasecmp($argv[2], "--update") == 0) {
		$action = DnssecAction::Update;
		echo "DNSSEC: will be REPLACED (in case configured).".PHP_EOL;
	}

}

// $targetOrganization = $argv[2];
// echo "Target organization: ".$targetOrganization.PHP_EOL;

try {
	//  2 functionalities that are implemented
	//  - Add DNSSEC to the domain in case it isn't signed yet.
	//  - Reconfigure the domain so it only contains the DNSSEC data from the file afterwards
	//    > yearly keys will expired and need to be refreshed

    // Please enter your own settings file here under before using this example
	if ($conn = eppConnection::create(getSettingsFileByTld($domainname), true)) {
        $conn->enableDnssec();
        if ($conn->login()) {

            $eppDomain = new eppDomain($domainname);

			// First retrieve info of the domain
			$eppDomainInfo = new eppInfoDomainRequest($eppDomain);
			if ($eppDomainInfoResponse = $conn->request($eppDomainInfo)) {

				$hasExistingDnssec = $eppDomainInfoResponse->hasDnsSec();

				if($hasExistingDnssec) {
					$keyCounter = $eppDomainInfoResponse->keyCount();
					echo " DNSSEC keys (already) configured. Key count: ".$keyCounter.PHP_EOL;
				} else {
					echo " DNSSEC not (yet / anymore) configured.".PHP_EOL;
				}

				if($action === DnssecAction::Remove && $hasExistingDnssec) {
					$removeDnsSecFromDomainResponse = removeDnsSecFromDomain($conn, $eppDomainInfoResponse, true);
					echo "  DNSSEC removed. Exit.".PHP_EOL;

				} else if (!$hasExistingDnssec && ($action === DnssecAction::Remove || $action === DnssecAction::Update)) {
					echo "  DNSSEC not configured, can't remove/update it. Use add action instead. Exit.".PHP_EOL;
					return;
				} else if ($hasExistingDnssec && $action === DnssecAction::Add) {
					echo "  DNSSEC not already configured, can't add. Use update action instead. Exit.".PHP_EOL;
					return;
				} else {

					// To Add or Update DNSSEC, we need the corresponding data from file.
					// As prerequisite we need a file with the DNSSEC data to execute one of the following
					$dnssecDataFromFile = importFromFile($domainname);

					echo " -------------".PHP_EOL;
					echo " TODO's".PHP_EOL;
					echo "  - imported lines as array to addDnsSecToDomain method.".PHP_EOL;
					echo "  - update action = combine remove+add in singel request.".PHP_EOL;
					echo " -------------".PHP_EOL;

					if(empty($dnssecDataFromFile)) {
						echo " DNSFILE not found in /dnssec folder";
						die();
					}

					switch ($action) {
						case DnssecAction::Add:
							addDnsSecToDomain($conn, $dnssecDataFromFile);
						break;
						case DnssecAction::Update:
							removeDnsSecFromDomain($conn, $eppDomainInfoResponse, true);
							addDnsSecToDomain($conn, $dnssecDataFromFile);
						break;
						default:
							echo "  Doing nothing to action '" . $action . "'. Exit.".PHP_EOL;
					}
				}

				showSummary($conn, $domainname);
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
 * @param $domainname string
 */
function importFromFile($domainname) {
	$dnsFilename = getDnsSecFilename($domainname);

	if(!file_exists(__DIR__ . $dnsFilename)) {
		echo " File not found! " . $dnsFilename .PHP_EOL;
		die();
	}

	$contentAsString = file_get_contents(__DIR__ . $dnsFilename, true);

	$fileLines = explode("\n", $contentAsString); //create array separate by new line

	if(is_countable($fileLines) && count($fileLines) > 0) {
		$resultCollection = [];

		foreach ($fileLines as $fileLine) {
			$elements = explode(" ", $fileLine);

			if(count($elements) > 0 && !empty($elements[0])) {

				$object = (object) [
					'domainname'=> rtrim(reset($elements), "."),
					'flags'		=> $elements[4],
					'algorithm'	=> $elements[6],
					'publicKey' => ltrim(rtrim($elements[7], ")"), "("), // Brackets are not compatible with EPP :(
					'keytag'	=> rtrim(end($elements))
				];

				if(strcasecmp($domainname, $object->domainname) != 0) {
					echo "DNSSEC file contents contains values for wrong domain!".PHP_EOL;
					die();
				}

				$resultCollection[] = $object;
			}
		}

		echo " DNSSEC data imported OK".PHP_EOL;

		return $resultCollection;
	}
	
	return null;
}

function addDnsSecToDomain($conn, $dnssecDataFromFile) {

	if(!is_array($dnssecDataFromFile) || empty($dnssecDataFromFile)) {
		echo " expected array with dnssec configured - but received none/empty";
		die(); 
	}

	$domainname = $dnssecDataFromFile[0]->domainname;
	$eppDomain = new eppDomain($domainname);

	foreach ($dnssecDataFromFile as $dnssecItem) {
		$dnssecFlags	= $dnssecItem->flags;
		$dnssecAlg		= $dnssecItem->algorithm;
		$dnssecPubKey	= $dnssecItem->publicKey;
		$dnssecKeyId	= $dnssecItem->keytag;
	
		$sec = new eppSecdns(); // Protocol is set to 3 by default.
		$sec->setKey($dnssecFlags, $dnssecAlg, $dnssecPubKey);
		$eppDomain->addSecdns($sec);
	    echo " DNSSEC key prepared with KeyId: ".$dnssecKeyId.PHP_EOL;
	}

	$eppDomainUpdateRequest = new eppDnssecUpdateDomainRequest($domainname, $eppDomain);
	if ($eppDomainUpdateResponse = $conn->request($eppDomainUpdateRequest)) { // getting result implies that update succeeded.

		// Move DNSSEC file into archive folder
		moveDnsSecFilename($domainname);
		echo "  (imported file moved to archive)".PHP_EOL;
	}
}

function removeDnsSecFromDomain($conn, $eppDomainInfoResponse, bool $removeExistingKeys) {

	if($eppDomainInfoResponse) {

		$eppDomainWithToBeRemovedDnssecKeys = $removeExistingKeys ? $eppDomainInfoResponse->getDomain() : null;
		if($removeExistingKeys) {
			// the eppDomainInfoResponse already has been checked for Dnssec
			// so we can retrieve the key data directly to determine any existing keys. 
			$existingDnssecKeyData = $eppDomainInfoResponse->getKeydata();
			// reconstruct the dnssec keys to the eppDomain-object

			if (is_array($existingDnssecKeyData) && (count($existingDnssecKeyData)>0)) {
				// This is messy. We have an infoResponse of a particular domain(object)
				// We need to pass that into the eppDnssecUpdateDomainRequest constructor, together 
				//  with an eppDomain object containing the keys to remove
				$eppDomainWithToBeRemovedDnssecKeys = $eppDomainInfoResponse->getDomain();

				foreach ($existingDnssecKeyData as $dnssecItem) {
					/* @var eppSecdns $dnssec */
					$eppDomainWithToBeRemovedDnssecKeys->addSecdns($dnssecItem);
				}
			}
		}

		// The eppDnssecUpdateDomainRequest can be called with either Add/Remove/Update parameter filled
		$eppDomainUpdateWithDnssecModificationsRequest = new eppDnssecUpdateDomainRequest($eppDomainInfoResponse->getDomain(), null, $eppDomainWithToBeRemovedDnssecKeys, null);

		// IMPORTANT HACK
		// This EPP implementation uses an "Update Domain Request" which can optionally include DNSSEC changes
		//  But the implementation is that once you send in 'remove dnssec' instructions, that also handles/NS removal will
		//  take place. It expects that you remove AND (re)add the new handles/NS you want. But as we only want to
		//  recycle the DNSSEC keys, we clear out these domain-level-instructions.
		$domainLevelRemovalInstructions 	= $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:rem');
		if(count($domainLevelRemovalInstructions) > 0) {
			$domainLevelRemovalInstruction = $domainLevelRemovalInstructions->item(0);
			$domainLevelRemovalInstruction	->parentNode->removeChild($domainLevelRemovalInstruction);
		}

		$domainLevelChangeInstructions = $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:chg');
		if(count($domainLevelChangeInstructions) > 0) {
			$domainLevelChangeInstruction = $domainLevelChangeInstructions->item(0);
			$domainLevelChangeInstruction ->parentNode->removeChild($domainLevelChangeInstruction);
		}

		$domainLevelAddInstructions = $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:add');
		if(count($domainLevelAddInstructions) > 0) {
			$domainLevelAddInstruction = $domainLevelAddInstructions->item(0);
			$domainLevelAddInstruction ->parentNode->removeChild($domainLevelAddInstruction);
		}

		// Finally ... execute the DNSSEC change
		if ($eppDomainUpdateWithDnssecModificationsResponse = $conn->request($eppDomainUpdateWithDnssecModificationsRequest)) {	
			echo " DNSSEC (all) keys removed.".PHP_EOL;
			return $eppDomainUpdateWithDnssecModificationsResponse;

		} else {
			echo "mismatch".PHP_EOL;
			die();
		}
	}	
}

function showSummary($conn, $domainname) {
	$eppDomain = new eppDomain($domainname);

	// (re)retrieve info of the domain
	$eppDomainInfo = new eppInfoDomainRequest($eppDomain);
	if ($eppDomainInfoResponse = $conn->request($eppDomainInfo)) {

		if($eppDomainInfoResponse->hasDnsSec()) {
			$dnssecData = $eppDomainInfoResponse->getKeydata();

			echo " DNSSEC keys after modifications: " . count($dnssecData) .PHP_EOL;

			foreach ($dnssecData as $secdns) {
			    // var_dump($secdns);
				echo "  > " . substr($secdns->getPubkey(), 0, 25) . " [..] " . PHP_EOL;
			}
		} 
		else {
			echo " DNSSEC not applicable.".PHP_EOL;
		}
	}
}