<?php
namespace Metaregistrar\EPP;


class eppDnssecInfoDomainResponse extends eppInfoDomainResponse
{
    /**
     * Retrieve the keyTag elements from the info response
     *
    <secDNS:infData>
        <secDNS:keyData>
            <secDNS:flags>256</secDNS:flags>
            <secDNS:protocol>3</secDNS:protocol>
            <secDNS:alg>8</secDNS:alg>
            <secDNS:pubKey>AwEAAbWM8nWQZbDZgJjyq+tLZwPLEXfZZjfvlRcmoAVZHgZJCPn/Ytu/iOsgci+yWgDT28ENzREAoAbKMflFFdhc5DNV27TZxhv8nMo9n2f+cyyRKbQ6oIAvMl7siT6WxrLxEBIMyoyFgDMbqGScn9k19Ppa8fwnpJgv0VUemfxGqHH9</secDNS:pubKey>
        </secDNS:keyData>
    </secDNS:infData>
     * @return array|null
     */
    public function getKeys() {
        // Check if dnssec is enabled on this interface
        if ($this->findNamespace('secDNS')) {
            $xpath = $this->xPath();
            $result = $xpath->query('/epp:epp/epp:response/epp:extension/secDNS:infData/*');
            $keys = array();
            if (count($result) > 0) {
                foreach ($result as $keydata) {
                    /* @var $keydata \DOMElement */
                    // Check if the pubKey element is present. If not, use getKeydata();
                    $test = $keydata->getElementsByTagName('pubKey');
                    if ($test->length > 0) {
                        $secdns = new eppSecdns();
                        $flags = $keydata->getElementsByTagName('flags')->item(0)->nodeValue;
                        $algorithm = $keydata->getElementsByTagName('alg')->item(0)->nodeValue;
                        $pubkey = $keydata->getElementsByTagName('pubKey')->item(0)->nodeValue;
                        $secdns->setKey($flags, $algorithm, $pubkey);
                        $keys[] = $secdns;
                    }
                }
            }
            return $keys;
        }
        return null;
    }
}