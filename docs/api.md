# Overview

**Canonical operator docs:** **pbx3-docs** → Instance API ([Methods and notation](https://pbx3-oss.github.io/pbx3-docs/api/overview/)).

## Methods

pbx3api uses the methods GET,POST,PUT,DELETE

* GET is used for retrieval but there are some exceptions, in particular where it is used to issue commands of some description
* POST is used to create NEW instances 
* PUT is used for idempotent operations, usually updates to instance variables but also to enliven a backup or a snapshot. 
* DELETE is used to remove/destroy an instance

**N.B.**

* ALL responses will be returned as JSON arrays and strings
* ALL regular POST/PUT bodies must ONLY contain raw JSON
* File upload (backups, greetings and snapshots) will use a regular POST Form and body

##Request notation

An HTTPS API Request will take the form:-

    METHOD /{resource}/{endpoint?}/{parameter?}

Where a variable ends with a question mark (?) it means the variable is optional.   e.g.

    GET /agents/{agent?}

Thus you can request either:-

    GET /agents

or:-

    GET /agents/1021

The first example above will return ALL agent endpoints, the second will return agent endpoint 1021. In the document a POST/PUT BODY will be described using regular Laravel validation array elements.   e.g. 
```
 'pkey' => 'required|integer|min:1000|max:9999';
 'cluster' => 'required|exists:cluster';
 'name' => 'required|alpha_dash';
 'passwd' => 'required|integer|min:1000|max:9999';
```

Most Laravel validators are self explanatory but you can find full information in the Laravel Documentation here:-  https://laravel.com/docs/11.x/validation#available-validation-rules.

* In the POST BODY, only the REQUIRED variables are listed.   i.e the minimum you need to supply to get the instance created.<br/>   
* In the PUT BODY, all of the update-able variables are listed.

You can freely supply non-required variables during a POST if you wish but you MUST provide ALL of the REQUIRED variables or the Method will fail.

##HTTP Status Codes

pbx3api will return a standard HTTP status code with each response.  For more information see here:-  https://www.restapitutorial.com/httpstatuscodes.html<br/>

Additionally, when required, pbx3api will return error messages in JSON format

*_________________________________________________________________________________________________*