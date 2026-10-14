# API Digest

**Canonical operator digest:** **pbx3-docs** → Instance API → [Endpoint reference](https://pbx3-oss.github.io/pbx3-docs/api/reference/). This file is a historical / repo-local stub and may lag `routes/api.php`.

## Agents
####GET /agents/{agent?}
####POST /agents
**Body:**
```
'pkey' => 'required|integer|min:1000|max:9999';
'cluster' => 'required|exists:cluster';
'name' => 'required|alpha_dash';
'passwd' => 'required|integer|min:1000|max:9999';
```

####PUT /agents/{agent}
**Body:**
```
'cluster' => 'exists:cluster,pkey',
'name' => 'alpha_dash',
'passwd' => 'integer',
'queue1' => 'exists:queue|nullable',
'queue2' => 'exists:queue|nullable',
'queue3' => 'exists:queue|nullable',
'queue4' => 'exists:queue|nullable',
'queue5' => 'exists:queue|nullable',
'queue6' => 'exists:queue|nullable'
```
####DELETE /agents/{agent}
---
##Backups
####GET /backups
return a list of available backups on the server
####GET /backups/{backup}
Download an existing backup from the server
####GET /backups/new
Request a new backup be taken and saved in the backup set
####POST /backups
Upload a local zipped backup to the backup set on the server
**Body:**
```
'uploadzip' => 'required|file|mimetypesapplication/zip'
```
####PUT /backups/{backup}
Restore an existing backup from the backup set to the live system.  Choose which elements of the backup set you want to restore by setting the corresponding element to true (N.B. this is JSON true - i.e. no quotes)
**Body:**
```
'restoredb' => 'boolean',
'restoreasterisk' => 'boolean',
'restoreusergreeting' => 'boolean',
'restorevmail' => 'boolean',
'restoreldap' => 'boolean'
```
####DELETE /backups/{backup}

-------
##Class of Service
There are three elements to CoS:-

* A rule (cosrule) which defines a particular dialplan set to be tested
* A closed instance of an egress rule (cosclose) which applies to a particular endpoint(extension)
* An open instance of an egress rule (cosopen) which applies to a particular endpoint(extension)

Class of service instances are accessed using the extension number as the key (NOT the Class of Service key)


##coscloses
####GET /coscloses
returns a list of the cosrule/extension intersections from the database
####GET /coscloses/{cosclose}
####POST /coscloses/{cosclose}
create a new cosclose instance
**Body:**
'ipphone_pkey' => 'exists:ipphone,pkey',
'cos_pkey' => 'exists:cos,pkey'
####DELETE/coscloses/{cosclose}

##cosopens
####GET /cosopens
returns a list of the cosrule/extension intersections from the database
####GET /cosopens/{cosopen}
####POST /cosopenes/{cosopen}
create a new cosopen instance
**Body:**
'ipphone_pkey' => 'exists:ipphone,pkey',
'cos_pkey' => 'exists:cos,pkey'
####DELETE/cosopens/{cosopen}

##cosrules
####GET /cosrules
returns a list of the cosrules from the database
####GET /cosrules/{cosrule}
####POST /cosrules/{cosrule}
create a new cosrule instance
**Body:**
'pkey' => 'required|alpha_dashy',
'dialplan' => 'required'
####DELETE/cosrules/{cosrule}
-----
## Custom Apps

Custom apps (tenant table `appl`) use the tenant-scoped pattern. The API sets `id` (KSUID) and `shortuid` on create; do not send them.

#### GET /customapps
Return list of custom apps (ordered by pkey).

#### GET /customapps/{customapp}
Return one custom app. Route binding by `pkey` (app name).

#### POST /customapps
Create a new custom app.

**Body:**
- `pkey` => required (app name; letters, numbers, underscore, hyphen)
- `cluster` => required|exists:cluster,pkey
- `cname` => string|nullable (display name)
- `description` => string|nullable
- `span` => in:Internal,External,Both,Neither
- `active` => in:YES,NO
- `striptags` => in:YES,NO
- `directdial` => integer|nullable
- `extcode` => string|nullable (extension/dialplan code)

#### PUT /customapps/{customapp}
Update a custom app. Route binding by `pkey`.

**Body:** Same updateable fields as above (no `pkey`; cluster, cname, description, span, active, striptags, directdial, extcode).

#### DELETE /customapps/{customapp}
Delete a custom app.
----
##Recurring Timers (Daytimers)
####GET /daytimer/{daytimer?}
####POST /daytimers
Create a new daytimer
**Body:**
```
'cluster' => 'required|exists:cluster,pkey',
'datemonth' => 'in:*,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31',
'dayofweek' => 'in:*,mon,tue,wed,thu,fri,sat,sun',
 'desc' => 'string',
'month' => 'in:*,jan,feb,mar,apr,may,jun,jul,aug,sep,oct,nov,dec',
'timespan' => [
'regex:/^\*|(2[0-3]|[01][0-9]):([0-5][0-9])\-(2[0-3]|[01][0-9]):([0-5][0-9])$/'
]
```

####PUT /daytimers/{daytimer}
update a timer
**Body:**
```
'cluster' => 'required|exists:cluster,pkey',
'datemonth' => 'in:*,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31',
'dayofweek' => 'in:*,mon,tue,wed,thu,fri,sat,sun',
'desc' => 'string',
'month' => 'in:*,jan,feb,mar,apr,may,jun,jul,aug,sep,oct,nov,dec',
'timespan' => [
'regex:/^\*|(2[0-3]|[01][0-9]):([0-5][0-9])\-(2[0-3]|[01][0-9]):([0-5][0-9])$/'
]
```

####DELETE  /daytimers/{daytimer}

-----------------

##Destinations
Destinations provides a list of all currently valid destinations within the database.  It has only one method.

####GET /destinations

------------------

##Extensions

Extensions have some extra functions to deal with multiple extension types and runtime information

####GET /extensions/{extension?}
Return a list or instance of an extension
####GET /extensions/export/pdf
Export extensions list as PDF (same dataset as list; returns application/pdf download). The same pattern applies for list export PDF: GET /queues/export/pdf, /trunks/export/pdf, /routes/export/pdf, /inboundroutes/export/pdf, /ivrs/export/pdf, /agents/export/pdf, /tenants/export/pdf, /conferences/export/pdf.
####GET /extensions/{extension}/runtime
Returns runtime information about  the extension from the PBX (i.e. CFIM, CFBS, ringdelay)
####POST /extensions/mailbox
Create a new mailbox instance

**Body:**
```
'pkey' => 'required',
'cluster' => 'required|exists:cluster,pkey'
```
####POST /extensions/provisioned
Create a new pbx3api provisioned instance

**Body:**
```
'pkey' => 'required',
'cluster' => 'required|exists:cluster,pkey'
'macaddr' => 'required|regex:/^[0-9a-fA-F]{12}$/'
```
####POST /extensions/unprovisioned
Create a new uprovisioned instance

**Body:**
```
'pkey' => 'required',
'cluster' => 'required|exists:cluster,pkey'
```

####POST /extensions/webrtc
Create a new webrtc instance

**Body:**
```
'pkey' => 'required',
'cluster' => 'required|exists:cluster,pkey'
```

####PUT /extensions/{extension}
Update an instance

**Body:**
```
'active' => 'in:YES,NO',
'callbackto' => 'in:desk,cell',
'callerid' => 'integer|nullable',
'cellphone' => 'integer|nullable',
'celltwin' => 'in:ON,OFF',
'cluster' => 'exists:cluster,pkey',
'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
'dvrvmail' => 'exists:ipphone,pkey|nullable',
'location' => 'in:local,remote',
'protocol' => 'in:IPV4,IPV6',
'provision' => 'string|nullable',
'provisionwith' => 'in:IP,FQDN',
'sndcreds' => 'in:No,Once,Always',
'transport' => 'in:udp,tcp,tls,wss',
'vmailfwd' => 'email|nullable'
```

####PUT /extensions/{extension}/runtime
Update runtime information for an instance

**Body:**
```
'cfim' => :
    ['regex:/^\+?\d+$/'],
    ['nullable'],
'cfbs' => 
    ['regex:/^\+?\d+$/'],
    ['nullable'],            
'ringdelay' => 'integer|nullable'
```
####DELETE /extensions/{extension}

----------------

##Firewalls
pbx3api uses the Shorewall firewall to protect its resources.   The API operates on the entire ruleset as a simple array.   GET will return the rules, POST will update the rules and validate them (using shorewall itself) and PUT will restart the firewall.

####GET /firewalls/ipv4
return the IPV4 firewall rules
####POST /firewalls/ipv4
Upload a rules array to the server and validate it.

**Body:**
```
'rules' => 'required'
```
####PUT /firewalls/ipv4
Restart the IPV4 firewall

####GET /firewalls/ipv6
return the IPV6 firewall rules
####POST /firewalls/ipv6
Upload a rules array to the server and validate it.

**Body:**
```
'rules' => 'required'
```
####PUT /firewalls/ipv6
Restart the IPV6 firewall

----------------------------

##Greetings

####GET /greetings
Returns a list of greetings
####GET /greetings/{greeting}
Download a greeting
####POST  /greetings
Upload a new greeting

**Body:**
```
'greeting' => 'required|file|mimes:wav,mpeg'
```
####DELETE /greetings/{greeting}
Delete a greeting

##Non Recurring Timers (Holidaytimers)
####GET /holidaytimers{holidaytimer?}
Return a list or instance of HolidayTimer.   Times are stored as epoch seconds.

####POST /holidaytimers
Create a new Holidaytimer

**Body:**
```
'cluster' => 'exists:cluster,pkey',
'route' => 'string',
'desc' => 'string',
'stime' => 'digits:10|nullable',
'etime' => 'digits:10|nullable'
```

####PUT /holidaytimers/{holidaytimer}
update a timer

**Body:**
```
'cluster' => 'exists:cluster,pkey',
'route' => 'string',
'desc' => 'string',
'stime' => 'digits:10|nullable',
'etime' => 'digits:10|nullable'
```
####DELETE  /holidaytimers/{holidaytimer}

-------------------------------

##Inbound Routes
Inbound routes (DDI's or DiD's) control the initial ingress into the PBX core
####GET /inboundroutes/{inboundroute?}
Return a list or instance of InboundRoute

####POST /inboundroutes
Create a new inboundroute

**Body:**
```
'pkey' => 'required'
'cluster' => 'required|exists:cluster'
'trunkname' => 'required'
```

####PUT /inboundroutes/{inboundroute}
update an inboundroute

**Body:**
```
'active' => 'in:YES,NO', 
'alertinfo' => 'string',
'closeroute' => 'string',
'cluster' => 'exists:cluster,pkey',
'description' => 'alpha_num',
'disa' => 'in:DISA,CALLBACK|nullable',
'disapass' => 'alpha_num|nullable',
'inprefix' => 'integer|nullable',
'moh' => 'in:ON,OFF',
'openroute' => 'string',
'swoclip' => 'in:YES,NO',
'tag' => 'alpha_num|nullable',
```
####DELETE  /inboundroutes/{inboundroute}

##IVRs

IVRs optionally control ACD into the PBX core
####GET /ivrs/{ivr?}
Return a list or instance of Ivr

####POST /ivrs
Create a new ivr

**Body:**
```
'pkey' => 'required'
'cluster' => 'required|exists:cluster'
```
####PUT /ivrs/{ivr}
update an ivr

**Body:**
```
'alert0' => 'string|nullable',
'alert1' => 'string|nullable',
'alert2' => 'string|nullable',
'alert3' => 'string|nullable',
'alert4' => 'string|nullable',
'alert5' => 'string|nullable',
'alert6' => 'string|nullable',
'alert7' => 'string|nullable',
'alert8' => 'string|nullable',
'alert9' => 'string|nullable',
'alert10' => 'string|nullable',
'alert11' => 'string|nullable',            
'description' => 'string|nullable',
'cluster' => 'exists:cluster,pkey',
'greetnum' => 'integer',
'listenforext' => 'in:YES,NO',
'option0' => 'string|nullable',
'option1' => 'string|nullable',
'option2' => 'string|nullable',
'option3' => 'string|nullable',
'option4' => 'string|nullable',
'option5' => 'string|nullable',
'option6' => 'string|nullable',
'option7' => 'string|nullable',
'option8' => 'string|nullable',
'option9' => 'string|nullable',
'option10' => 'string|nullable',
'option11' => 'string|nullable',
'tag0' => 'string|nullable',
'tag1' => 'string|nullable',
'tag2' => 'string|nullable',
'tag3' => 'string|nullable',
'tag4' => 'string|nullable',
'tag5' => 'string|nullable',
'tag6' => 'string|nullable',
'tag7' => 'string|nullable',
'tag8' => 'string|nullable',
'tag9' => 'string|nullable',
'tag10' => 'string|nullable',
'tag11' => 'string|nullable',            
'timeout' => 'operator',
```
####DELETE  /ivrs/{ivr}

----------------------------------
##Logs/CDRs
Here is a placeholder for general logs.   In the initial out it only has a method to retrieve CDR.
####GET /logs/cdrs/{limit?}
Download the CDR log.   If {limit} is specified, download the last {limit} rows

--------------------------------

##Queues
Queues optionally control ACD into the PBX core
####GET /queues{queue?}
Return a list or instance of Queue
####POST /queues
Create a new queue

**Body:**
```
'pkey' => 'required'
'cluster' => 'required|exists:cluster'
```
####PUT /queues/{queue}
update a queue

**Body:**
```
'conf' => 'string',
'cluster' => 'exists:cluster,pkey',
'devicerec' => 'in:None,OTR,OTRR,Inbound',
'greetnum' => 'regex:/^usergreeting\d{4}$',
'options' => 'alpha',
```
####DELETE  /queues/{queue}
------------------------------------------

##Snapshots##
A snapshot is an instance of the pbx3api db. 
####GET /snapshots
return a list of available snapshots on the server
####GET /snapshots/{snapshot}
Download an existing snapshot from the server
####GET /snapshots/new
Request a new snapshot be taken and saved in the snapshot set
####POST /snapshots
Upload a local snapshot to the snapshot set on the server

**Body:**
```
'uploadsnap' => 'required|file|mimetypes:application/zip'
```
####PUT /snapshots/{snapshot}
Restore an existing snapshot from the snapshot set to the live system.

####DELETE /snapshots/{snapshot}

-----------------------------------

##Routes
####GET /routes/{route?}
return a list or instance of ringgroup
####POST /route
Create a new route

**Body:**
```
'pkey' => 'required'
'cluster' = 'required|exists:cluster,'
'outcome' = 'required;
```
####PUT /routes/{route}
Update an existing route

**Body:**
```
'active' => 'in:YES,NO',
'auth' => 'in:YES,NO',
'cluster' => 'exists:cluster,pkey',
'desc' => 'alpha_dash',
'dialplan' => 'string',
'path1' => 'exists:trunks,pkey|nullable',
'path2' => 'exists:trunks,pkey|nullable',
'path3' => 'exists:trunks,pkey|nullable',
'path4' => 'exists:trunks,pkey|nullable',
'strategy' => 'in:hunt,balance'
```

####DELETE /routes/{route}

------------------------------------------

##System Commands
System commands use the GET method
####GET /syscommands/{command}
Available Commands

* commit
* reboot
* pbxrunstate
* pbxstart
* pbxstop

-----------------------------------------

##System Globals
Globals are system wide variables which control PBX default behaviours.  They cannot be created or deleted.
####GET /sysglobals
return a list of global settings
####PUT /sysglobals
update Global settings

**Body:**
```
'ABSTIMEOUT' => 'integer',
'ACL' => 'in:NO,YES',
'AGENTSTART' => 'integer',
'ALERT' => 'email',
'ALLOWHASHXFER' => 'in:enabled,disabled',
'BLINDBUSY' => 'integer|nullable',
'BOUNCEALERT' => 'integer|nullable',
'CALLPARKING' => 'in:NO,YES',
'CALLRECORD1' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
'CAMPONQONOFF' => 'in:OFF,ON',
'CAMPONQOPT' => 'string|nullable',
'CFWDEXTRNRULE' => 'in:enabled,disabled',
'CFWDPROGRESS' => 'in:enabled,disabled',
'CFWDANSWER' => 'in:enabled,disabled',
'CLUSTER' => 'in:ON,OFF',
'CONFTYPE' => 'in:simple,hosted',
'COSSTART' => 'in:ON,OFF',
'COUNTRYCODE' => 'alpha|size:2',
'EURL' => 'regex:/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
'EMERGENCY' => 'digits:3',
'FQDN' => 'regex:/^(?!:\/\/)(?=.{1,255}$)((.{1,63}\.){1,127}(?![0-9]*$)[a-z0-9-]+\.?)$/i',
'FQDNINSPECT' => 'in:NO,YES',
'FQDNPROV' => 'in:NO,YES',
'INTRINGDELAY' => 'integer',
'IVRKEYWAIT' => 'integer|nullable',
'IVRDIGITWAIT' => 'integer|nullable',
'LACL' => 'in:NO,YES',
'LDAPBASE' => 'string|nullable',
'LDAPOU' => 'string|nullable',
'LDAPUSER' => 'string|nullable',
'LDAPPASS' => 'string|nullable',
'LEASEHDTIME' => 'integer',
'LOCALIP' => 'ip',
'LOGLEVEL' => 'integer',    	
'LOGSIPDISPSIZE' => 'integer',
'LOGSIPNUMFILES' => 'integer',
'LOGSIPFILESIZE' => 'integer',
'LTERM' => 'in:NO,YES',
'MAXIN' => 'integer',
'MIXMONITOR' => 'in:NO,YES',
'MONITOROUT' => 'string',
'MONITORSTAGE' => 'string',
'MONITORTYPE' => 'in:monitor,mixmonitor',
'NATDEFAULT' => 'in:local,remote',
'OPERATOR' => 'integer',
'PWDLEN' => 'integer',    
'PLAYBEEP' => 'in:YES.NO',
'PLAYBUSY' => 'in:YES,NO',
'PLAYCONGESTED' => 'in:YES,NO',
'PLAYTRANSFER' => 'in:YES,NO',
'RECFINALDEST' => 'string',
'RECLIMIT' => 'integer',
'RECQDITHER' => 'integer',
'RECQSEARCHLIM' => 'integer',    	
'RINGDELAY' => 'integer',
'SESSIONTIMOUT' => 'integer',
'SENDEDOMAIN' => 'in:YES,NO',
'SIPIAXSTART' => 'integer',
'SIPFLOOD' => 'in:NO,YES',
'SPYPASS' => 'integer',
'SUPEMAIL' => 'email|nullable',
'SYSOP' => 'integer',
'SYSPASS' => 'integer',    
'TLSPORT' => 'integer',
'USEROTP' => 'string',
'USERCREATE' => 'in:NO,YES',
'VDELAY' => 'integer',
'VMAILAGE' => 'integer',
'VOICEINSTR' => 'in:YES,NO',
'VOIPMAX' => 'integer',
'VXT' => 'in:0,1',
'ZTP' => 'in:disabled,enabled'
```

---------------------------------------

##Templates
Templates are used to create provisioning streams
####GET /templates/{template?}
Return a list or instance of Template
####POST /templates
Create a new template

**Body:**
```
'pkey' => 'required'
'technology' => 'required|in:SIP,Descriptor,BLF Template';
```
####PUT /templatess/{template}
update a template

**Body:**
```
'blfkeyname' => 'exists:device,pkey|nullable',
'desc' => 'string',
'provision' => 'string|nullable',
'technology' => 'in:SIP,Descriptor,BLF Template';
```
####DELETE //templatess/{template}
Delete a template.  Only user created templates can be deleted.  System templates can not.

------------------------------------------------

##Tenants
Tenants are used to define classes of  PBX resources.  Usually, but not always, they are customers.  For historical reasons, Tenants are internally referred to as clusters.
####GET /tenants/{tenants?}
Return a list or instance of Tenant
####POST /tenants
Create a new tenant

**Body:**
```
'pkey' => 'string|required'
'description' => 'string|required'
```
####PUT /tenants/{tenant}
update a tenant

**Body:**
```
    		'abstimeout' => 'integer',
			'allow_hash_xfer' => 'in:enabled,disabled',
			'callrecord1' => 'in:None,In,Out,Both',
			'cfwdextern_rule' => 'In:YES,NO',
			'cfwd_progress' => 'in:enabled,disabled',
			'cfwd_answer' => 'in:enabled,disabled',
			'clusterclid' => 'nullable|string|regex:/^\\d*$/',
			'chanmax' => 'integer',
			'countrycode' => 'integer',
			'dynamicfeatures' => 'string',
			'description' => 'string',
			'emergency' => 'integer',
			'int_ring_delay' => 'integer',
			'ivr_key_wait' => 'integer',
			'ivr_digit_wait' => 'integer',
			'language' => 'string',
			'ldapanonbind' => 'YES',
			'ldapbase' => 'string',
			'ldaphost' => 'string',
			'ldapou' => 'string',
			'ldapuser' => 'string',
			'ldappass' => 'string',
			'ldaptls' => 'in:on,off',
			'localarea' => 'numeric|nullable',
			'localdplan' => [
					'regex:/^_X+$/',
					'nullable'
			],
			'lterm' => 'boolean',
			'leasedhdtime' => 'integer|nullable',
			'masteroclo' => 'in:AUTO,CLOSED',
			'max_in' => 'integer',
			'monitor_out' => 'string',
			'operator' => 'integer',
			'pickupgroup' => 'string',
			'play_beep' => 'boolean',
			'play_busy' => 'boolean',
			'play_congested' => 'boolean',
			'play_transfer' => 'boolean',
			'rec_age' => 'integer',
			'rec_final_dest' => 'string',
			'rec_file_dlim' => 'string',
			'rec_grace' => 'integer',
			'rec_limit' => 'integer',
			'rec_mount' => 'integer',
			'recmaxage' => 'integer',
			'recmaxsize' => 'integer',
			'recused' => 'integer',
			'ringdelay' => 'integer',
			'routeoverride' => 'integer',
			'spy_pass' => 'integer',
			'sysop' => 'integer',
			'syspass' => 'integer',
			'usemohcustom' => 'integer|nullable',
			'vmail_age' => 'integer',
			'voice_instr' => 'boolean',
			'voip_max' => 'integer'
```
####DELETE /tenants/{tenant}
Deleting a tenant will delete ALL of its dependencies.

--------------------------------------------------------------------

##Trunks
Trunks are used to define peers.   Mostly you can think of them as axes of egress from the PBX (Ingress is usually defined by DDI/DiD abstractions)
####GET /trunks/{trunk?}
Return a list or instance of Trunks

####POST /trunks
Create a new trunk

**Body:**
```
'pkey' => 'required'
'technology' => 'required|in:SIP,IAX2'
'cluster' => 'required|exists:cluster,pkey'
'username' => 'required'
'host' => 'required'
```
####PUT /trunks/{trunk}
update a trunk

**Body:**
```
'active' => 'in:YES,NO', 
'alertinfo' => 'string',
'callerid' => 'integer',
'callprogress' => 'in:ON,OFF',
'cluster' => 'exists:cluster,pkey',
'description' => 'alpha_num',
'devicerec' => 'in:None,OTR,OTRR,Inbound.Outbound,Both',
'disa' => 'in:DISA,CALLBACK|nullable',
'disapass' => 'alpha_num|nullable',
'host' => 'string', 
'inprefix' => 'integer|nullable',
'match' => 'integer|nullable',
'moh' => 'in:ON,OFF',
'password' => 'alpha_num|nullable',
'peername' => 'string',
'register' => 'string|nullable',
'swoclip' => 'in:YES,NO',
'tag' => 'alpha_num|nullable',
'transform' => [
:'regex:/$(\d+?:\d+?\s*)+',
:'nullable'
],
'trunkname' => 'alpha_num',
```
####DELETE /trunks/{trunk}

--------------------------------------------------

##Asterisk AMI functions exposed by the API
pbx3api gives you access to a range of functions from the Asterisk Manager Interface(AMI).  This allows you to examine, and in some cases change, state information from the running PBX.   
**N.B.** pbx3api implements blocking AMI requests.  if you want to access non-blocking AMI streams then you can look at something like PAMI.

* Most of the AMI functions use GET.   They return either an instance list or a single instance.  
* The AMI ''''Originate'''' bridging function uses POST 
* Asterisk internal database ''''DBPut'''' uses PUT
* Asterisk internal database ''''DBDel'''' uses DELETE
* AMI function names are case sensitive for consistency with the Asterisk AMI documentation (of which, there is very little).  
* We have not documented the returned variables so you will need to run the requests using something like Postman and decide what you want by examination.

####List AMI Functions
Each of these functions will return a JSON array

####GET /astamis/ 

* Agents
* ConfbridgeList/{''room-number''} 
* ConfbridgeListRooms       
* CoreShowChannels
* DeviceStateList
* ExtensionStateList
* IAXpeers
* IAXregistry
* QueueStatus  
* QueueSummary                
* SIPpeers 
* SIPshowregistry
* Status
* VoicemailUsersList

####Instance AMI Functions

GET /astamis/ 

* CoreSettings
* CoreStatus
* ExtensionState/{''id''}{''context?''}
* MailboxCount/{''id''}
* MailboxStatus/{''id''}
* QueueStatus/{''id''}
* QueueSummary/{''id}''
* Reload
* SIPshowpeer/{''id''}

####POST /astamis/originate
originate a new bridge

**Body:**

```
target = 'required|integer';
caller = 'required|numeric';
context = 'required|alpha_dash'
clid = 'required|numeric';
```

##Asterisk internal DB (AstDB) functions
####GET /astamis/DBPGet/{id}/{key}
####PUT /astamis/DBPut/{id}/{key}(value)
####DELETE /astamis/DBdel/{id}/{key}

-----------------------------------

###Asterisk soft Hangup

####DELETE /astamis/Hangup/{id}/{key}

Hangup perfoms a "soft" hangup on a running channel.  The actual channel can be either side of the call bridge, it doesn't matter which you choose in a regular two-party call.  As an example, let's say we wish to hangup a channel called PJSIP/44107-00000155.   Here's what you would send:-

```
https://sip.mypbx.com:44300/api/astamis/Hangup/PJSIP/44107-00000155
```
