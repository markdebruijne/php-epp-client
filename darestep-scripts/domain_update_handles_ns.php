<?php
require('autoloader.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppUpdateDomainRequest;
use Metaregistrar\EPP\eppUpdateDomainResponse;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppDomain;

/*
 * This sample script modifies a domain name within your account
 * 
 * The nameservers of metaregistrar are used as nameservers
 * In this scrips, the same contact id is used for registrant, admin-contact, tech-contact and billing contact
 * Recommended usage is that you use a tech-contact and billing contact of your own, and set registrant and admin-contact to the domain name owner or reseller.
 */


if ($argc <= 1) {
    echo "Usage: modifydomain.php <domainname>\n";
    echo "Please enter the domain name to be modified\n\n";
    die();
}

$domainname = $argv[1];

echo "Modifying $domainname\n";
try {
    // Please enter your own settings file here under before using this example
    if ($conn = eppConnection::create('', true)) {
        // Connect to the EPP server
        if ($conn->login()) {

			// Capgemini handles / NS
			$nameserversCapgemini = array('ns1.capgeminioutsourcing.nl', 'ns2.capgeminioutsourcing.nl', 'ns3.capgeminioutsourcing.nl');
			$techcontactCapgeminiSIDN			= 'ROG005568-CAPGE';

			// Darestep handles (MetaRegistrar)
			$billingcontactMetaRegistrar		= null;
			$techcontactDarestepMetaRegistrar	= null;

			// Darestep handles (SIDN)
			$techcontactDarestepSIDN			= 'DAR007081-CAPGE';

			// Eneco Group -> Eneco brand
			$registrantEnecoGroupEnecoSIDN		= 'ENE001257-CAPGE';
			$admincontactEnecoGroupEnecoSIDN	= 'BAR055872-CAPGE';
			// Eneco Group -> OXXIO brand
			$registrantEnecoGroupOxxioSIDN		= 'OXX000031-CAPGE';
			$admincontactEnecoGroupOxxioSIDN	= 'BAR061438-CAPGE';
			// Eneco Group -> WOONENERGIE brand
			$registrantEnecoGroupWoonEnergieSIDN	= 'CEN009005-CAPGE';
			$admincontactEnecoGroupWoonEnergieSIDN	= 'BAR061438-CAPGE';
			
			
            // UPDATE Domain for Eneco Group - ENECO brand
			// modifydomain($conn, $domainname, $registrantEnecoGroupEnecoSIDN, $admincontactEnecoGroupEnecoSIDN, array($techcontactDarestepSIDN, $techcontactCapgeminiSIDN), null, $nameserversCapgemini);
			// UPDATE Domain for Eneco Group - OXXIO brand
			// modifydomain($conn, $domainname, $registrantEnecoGroupOxxioSIDN, $admincontactEnecoGroupOxxioSIDN, array($techcontactDarestepSIDN, $techcontactCapgeminiSIDN), null, $nameserversCapgemini);
			// UPDATE Domain for Eneco Group - WOONENERGIE brand
			modifydomain($conn, $domainname, $registrantEnecoGroupWoonEnergieSIDN, $admincontactEnecoGroupWoonEnergieSIDN, array($techcontactDarestepSIDN, $techcontactCapgeminiSIDN), null, $nameserversCapgemini);

            $conn->logout();
        }
    }
} catch (eppException $e) {
    echo $e->getMessage() . "\n";
}

/**
 * @param $conn eppConnection
 * @param $domainname string
 * @param null $registrant string
 * @param null $admincontact string
 * @param null $techcontact string
 * @param null $billingcontact string
 * @param null $nameservers string
 */
function modifydomain($conn, $domainname, $registrant = null, $admincontact = null, $techcontact = null, $billingcontact = null, $nameservers = null) {
    $response = null;
    try {
        // First, retrieve the current domain info. Nameservers can be unset and then set again.
        $del = null;
        $domain = new eppDomain($domainname);
        $info = new eppInfoDomainRequest($domain);
        if ($response = $conn->request($info)) {
            // If new nameservers are given, get the old ones to remove them
            if (is_array($nameservers)) {
                /* @var Metaregistrar\EPP\eppInfoDomainResponse $response */
                $oldns = $response->getDomainNameservers();
                if (is_array($oldns)) {
                    if (!$del) {
                        $del = new eppDomain($domainname);
                    }
                    foreach ($oldns as $ns) {
                        $del->addHost($ns);
                    }
                }
            }

            if ($admincontact) {
                $oldadmin = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_ADMIN);
                if ($oldadmin == $admincontact) {
                    $admincontact = null;
					echo "Handle-Admin already equals requested handle.".PHP_EOL;
                } else {
                    if (!$del) {
                        $del = new eppDomain($domainname);
                    }
                    $del->addContact(new eppContactHandle($oldadmin, eppContactHandle::CONTACT_TYPE_ADMIN));
					echo "Handle-Admin will be disconnected: ".$admincontact.PHP_EOL;
                }
            }

            if ($techcontact) {
                $oldtech = $response->getDomainContact(eppContactHandle::CONTACT_TYPE_TECH);

				$oldtechhandles = is_array($oldtech) ? $oldtech : array($oldtech);
				sort($oldtechhandles, SORT_STRING | SORT_FLAG_CASE);
				$requestedtechhandles = is_array($techcontact) ? $techcontact : array($techcontact);
				sort($requestedtechhandles, SORT_STRING | SORT_FLAG_CASE);

				$equallength = count($oldtechhandles) == count($requestedtechhandles);
				$equalvalues = $oldtechhandles == $requestedtechhandles;

                if ($equallength and $equalvalues) {
                    $techcontact = null;
					echo "Handle-Tech(s) already equals requested handle(s).".PHP_EOL;
                } else {
                    if (!$del) {
                        $del = new eppDomain($domainname);
                    }

					// remove ALL old tech handles that are not being re-requested
					foreach ($oldtechhandles as $techhandle) {
						if(!in_array(strtoupper($techhandle), array_map('strtoupper', $requestedtechhandles))) {
							$del->addContact(new eppContactHandle($techhandle, eppContactHandle::CONTACT_TYPE_TECH));
							echo "Handle-Tech will be disconnected: ".$techhandle.PHP_EOL;
						}
					}
                }
            }
        }

        // In the UpdateDomain command you can set or add parameters
        // - Registrant is always set (you can only have one registrant)
        // - Admin, Tech, Billing contacts are Added (you can have multiple contacts, don't forget to remove the old ones)
        // - Nameservers are Added (you can have multiple nameservers, don't forget to remove the old ones
        $mod = null;
        if ($registrant) {
            $mod = new eppDomain($domainname);
            $mod->setRegistrant(new eppContactHandle($registrant));
			echo "Handle-Reg will be set to: " . $registrant . PHP_EOL;
        }
        $add = null;
        if ($admincontact) {
            if (!$add) {
                $add = new eppDomain($domainname);
            }
            $add->addContact(new eppContactHandle($admincontact, eppContactHandle::CONTACT_TYPE_ADMIN));
			echo " Handle-Admin will be set to: ".$admincontact.PHP_EOL;
        }
        if ($techcontact) {
            if (!$add) {
                $add = new eppDomain($domainname);
            }

			$newtechhandles = is_array($techcontact) ? $techcontact : array($techcontact);
			
			foreach ($newtechhandles as $techhandle) {
				echo " Handle-Tech will be set to: ".$techhandle.PHP_EOL;
				$add->addContact(new eppContactHandle($techhandle, eppContactHandle::CONTACT_TYPE_TECH));
			}
        }
        if ($billingcontact) {
            if (!$add) {
                $add = new eppDomain($domainname);
            }
            $add->addContact(new eppContactHandle($billingcontact, eppContactHandle::CONTACT_TYPE_BILLING));
        }
        if (is_array($nameservers)) {
            if (!$add) {
                $add = new eppDomain($domainname);
            }
            foreach ($nameservers as $nameserver) {
                $add->addHost(new eppHost($nameserver));
            }
        }
        $update = new eppUpdateDomainRequest($domain, $add, $del, $mod);
        //echo $update->saveXML();
        if ($response = $conn->request($update)) {
            /* @var eppUpdateDomainResponse $response */
			$responseMessage = $response->getResultMessage();
            echo $responseMessage . "\n";

			file_put_contents("modifydomain_OK_" . $domainname .".txt", $responseMessage.PHP_EOL.PHP_EOL.$update->saveXML().PHP_EOL, FILE_APPEND);
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
        if ($response instanceof eppUpdateDomainResponse) {
            echo $response->textContent . "\n";

			file_put_contents("modifydomain_ERROR_" . $domainname .".txt", ($response->textContent).PHP_EOL, FILE_APPEND);
        }
    }
}