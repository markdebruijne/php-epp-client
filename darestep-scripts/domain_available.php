<?php
require('autoloader.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckDomainResponse;


/*
 * This script checks for the availability of domain names
 * You can specify multiple domain names to be checked
 */

if ($argc <= 1) {
    echo "Usage: checkdomain.php <domainnames>\n";
    echo "Please enter one or more domain names to check\n\n";
    die();
}

for ($i = 1; $i < $argc; $i++) {
    $domains[] = $argv[$i];
}

echo "Checking " . count($domains) . " domain names\n";
try {
    // Please enter the location of the file with these settings in the string location here under
    if ($conn = eppConnection::create("settings.ini", true)) {
        // Connect and login to the EPP server

        if ($conn->login()) {
            // Check domain names
            checkdomains($conn, $domains);
            $conn->logout();
        }
    }
} catch (eppException $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

/**
 * @param $conn eppConnection
 * @param $domains array of domain names
 */
function checkdomains($conn, $domains) {
    // Create request to be sent to EPP service
    $check = new eppCheckDomainRequest($domains);
    // Write request to EPP service, read and check the results
    if ($response = $conn->request($check)) {
        /* @var $response eppCheckDomainResponse */
        // Walk through the results
        $checks = $response->getCheckedDomains();
        foreach ($checks as $check) {
            echo " " . $check['domainname'] . " is " . ($check['available'] ? 'free' : 'taken');
            if ($check['available']) {
                echo ' (' . $check['reason'] .')';
            }
            echo "\n";
        }
    }
}