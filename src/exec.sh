#!/bin/bash

count=$(cat ucrm.json | grep -c '"pluginPublicUrl":null')
if [[ $count -eq 0 ]]
then
	ansible-playbook /adblock/ips.yml
else
	echo "Service is disabled"
fi
