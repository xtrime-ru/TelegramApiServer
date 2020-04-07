# TelegramApiServer
Fast, simple, async php telegram api server: 
[MadelineProto](https://github.com/danog/MadelineProto) and [AmpPhp](https://github.com/amphp/amp) Server

* Online server for tests: [tg.i-c-a.su](https://tg.i-c-a.su)
* My content aggregator: [i-c-a.su](https://i-c-a.su)
* Im using this micro-service with: [my TelegramRSS micro-service](https://github.com/xtrime-ru/TelegramRSS) 

## Features

* Fast async amp http server
* Full access to telegram api: bot and user
* Multiple sessions
* Stream media (view files in browser)
* Upload media
* Websocket endpoint for events

**Architecture Example**
![Architecture Example](https://hsto.org/webt/j-/ob/ky/j-obkye1dv68ngsrgi12qevutra.png)
 
## Installation

Docker: https://hub.docker.com/r/xtrime/telegram-api-server

Manual: 
1. Git clone this repo
1. `composer install -o --no-dev` to install required libs
1. Create .env from .env.example
1. Fill .env
1. Get app_id and app_hash at [my.telegram.org](https://my.telegram.org/) or leave blank.
   MadelineProto will generate them on start.

     _Optional:_
1. Use supervisor to monitor and restart swoole/amphp servers. Example of `/etc/supervisor/conf.d/telegram_api_server.conf`: 
    ```
    [program:telegram_client]
    command=/usr/bin/php /home/admin/web/tg.i-c-a.su/TelegramApiServer/server.php --session=session
    numprocs=1
    directory=/home/admin/web/tg.i-c-a.su/TelegramApiServer/
    autostart=true
    autorestart=true
    startretries=10
    stdout_logfile=/var/log/telegram/stdout.log
    redirect_stderr=true
    ```

## Usage

1. Run server/parser
    ```
    usage: php server.php [--help] [-a=|--address=127.0.0.1] [-p=|--port=9503] [-s=|--session=]
    
    Options:
            --help      Show this message
            
        -a  --address   Server ip (optional) (default: 127.0.0.1)
                        To listen external connections use 0.0.0.0 and fill IP_WHITELIST in .env
                        
        -p  --port      Server port (optional) (default: 9503)
        
        -s  --session   Name for session file (optional)
                        Multiple sessions can be specified: "--session=user --session=bot"
                        
                        Each session is stored in `sessions/%session%.madeline`. 
                        Nested folders supported.
                        See README for more examples.
   
    Also  options can be set in .env file (see .env.example)
    ```
1. Access Telegram API with simple GET/POST requests.

    Regular and application/json POST supported.
    Its recommended to use http_build_query, when using GET requests.
    
    **Rules:**
    * All methods from MadelineProto supported: [Methods List](https://docs.madelineproto.xyz/API_docs/methods/)
    * Url: `http://%address%:%port%/api[/%session%]/%class%.%method%/?%param%=%val%`
    * <b>Important: api available only from ip in whitelist.</b> 
        By default it is: `127.0.0.1`
        You can add client ip in .env file to `API_CLIENT_WHITELIST` (use json format)
    * If method is inside class (messages, contacts and etc.) use '.' to separate class from method: 
        `http://127.0.0.1:9503/api/contacts.getContacts`
    * If method requires array of values, use any name of array, for example 'data': 
        `?data[peer]=@xtrime&data[message]=Hello!`. Order of parameters does't matter in this case.
    * If method requires one or multiple separate parameters (not inside array) then pass parameters with any names but **in strict order**: 
        `http://127.0.0.1:9503/api/getInfo/?id=@xtrime` or `http://127.0.0.1:9503/api/getInfo/?abcd=@xtrime` works the same

    **Examples:**
    * get_info about channel/user: `http://127.0.0.1:9503/api/getInfo/?id=@xtrime`
    * get_info about currect account: `http://127.0.0.1:9503/api/getSelf`
    * repost: `http://127.0.0.1:9503/api/messages.forwardMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id]=1234`
    * get messages from channel/user: `http://127.0.0.1:9503/api/getHistory/?data[peer]=@breakingmash&data[limit]=10`
    * get messages with text in HTML: `http://127.0.0.1:9503/api/getHistoryHtml/?data[peer]=@breakingmash&data[limit]=10`
    * search: `http://127.0.0.1:9503/api/searchGlobal/?data[q]=Hello%20World&data[limit]=10`
    * sendMessage: `http://127.0.0.1:9503/api/sendMessage/?data[peer]=@xtrime&data[message]=Hello!`
    * copy message from one channel to another (not repost): `http://127.0.0.1:9503/api/copyMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id][0]=1`

## Advanced features
### Uploading files.

To upload files from POST request use custom `uploadMediaForm` method:

`curl "http://127.0.0.1:9503/api/uploadMediaForm" -g -F "file=@/Users/xtrime/Downloads/test.txt"`
Method supports `application/x-www-form-urlencoded` and `multipart/form-data`.

Send result from `uploadMediaForm` to `messages.sendMedia`:
```
curl --location --request POST 'http://127.0.0.1:9503/api/sendMedia' \
--header 'Content-Type: application/json' \
--data-raw '{
	"data":{
	    "peer": "@xtrime",
        "media": {
            "_": "inputMediaUploadedDocument",
            "file": {
                "_": "inputFile",
                "id": 1164670976363200575,
                "parts": 1,
                "name": "test.txt",
                "mime_type": "text/plain",
                "md5_checksum": ""
            },
            "attributes": [
                {
                    "_": "documentAttributeFilename",
                    "file_name": "test.txt"
                }
            ]
        }
    }
}'
```
Also see: https://docs.madelineproto.xyz/docs/FILES.html#uploading-files

### Downloading files

```
curl --location --request POST '127.0.0.1:9503/api/downloadToResponse' \
--header 'Content-Type: application/json' \
--data-raw '{
    "media": {
        "_": "messageMediaDocument",
        "document": {
            "_": "document",
            "id": 5470079466401169993,
            "access_hash": -6754208767885394084,
            "file_reference": {
                "_": "bytes",
                "bytes": "AkKdqJkAACnyXiaBgp3M3DfBh8C0+mGKXwSsGUY="
            },
            "date": 1551713685,
            "mime_type": "video/mp4",
            "size": 400967,
            "dc_id": 2
        }
    }
}'
```

Also see: https://docs.madelineproto.xyz/docs/FILES.html#downloading-files

### Multiple sessions support. 

When running  multiple sessions, need to define which session to use for request.
Each session is stored in `sessions/{$session}.madeline`. Nested folders supported.
**Examples:**
* `php server.php --session=bot --session=users/xtrime --session=users/user1`
* `http://127.0.0.1:9503/api/bot/getSelf`
* `http://127.0.0.1:9503/api/users/xtrime/getSelf` 
* `http://127.0.0.1:9503/api/users/user1/getSelf`
* sessions file paths are: `sessions/bot.madeline`, `sessions/users/xtrime.madeline` and `sessions/users/user1.madeline`
* glob syntax for sessions:
    * `--session=*` to use all `sessions/*.madeline` files.
    * `--session=users/* --session=bots/*`  to use all session files from `sessions/bots` and `sessions/users` folders. 

### Session management
    
**Examples:**
* Session list: `http://127.0.0.1:9503/system/getSessionList`
* Adding session: `http://127.0.0.1:9503/system/addSession?session=users/xtrime`
* [optional] Adding session with custom settings: `http://127.0.0.1:9503/system/addSession?session=users/xtrime&settings[app_info][app_id]=xxx&&settings[app_info][app_hash]=xxx`
* Removing session: `http://127.0.0.1:9503/system/removeSession?session=users/xtrime`
   
If there is no authorization in session, or session file is blank, authorization required:

User: 
* `http://127.0.0.1:9503/api/users/xtrime/phoneLogin?phone=+7123...`
* `http://127.0.0.1:9503/api/users/xtrime/completePhoneLogin?code=123456`
* (optional) `http://127.0.0.1:9503/api/users/xtrime/complete2falogin?password=123456`
* (optional) `http://127.0.0.1:9503/api/users/xtrime/completeSignup?firstName=MyExampleName`

Bot:
* `http://127.0.0.1:9503/api/bot/botLogin?token=34298141894:aflknsaflknLKNFS`

After authorization eventHandler need to be set, to receive updates for new session in `/events` websocket:
* `http://127.0.0.1:9503/api/users/xtrime/setEventHandler`
* `http://127.0.0.1:9503/api/bot/setEventHandler`

Save new session to file immediately: `http://127.0.0.1:9503/api/bot/serialize`

### Websocket
#### EventHandler updates (webhooks).
 
Connect to `ws://127.0.0.1:9503/events` to get all events in json. 
This is efficient alternative for webhooks.
Each event is json object in [json-rpc 2.0 format](https://www.jsonrpc.org/specification#response_object). Example: 

When using multiple sessions, name of session can be added to path of websocket endpoint: 
This endpoint will send events only from `users/xtrime` session: `ws://127.0.0.1:9503/events/users/xtrime`

PHP websocket client example: [websocket-events.php](https://github.com/xtrime-ru/TelegramApiServer/blob/master/examples/websocket-events.php)

`php examples/websocket-events.php --url=ws://127.0.0.1:9503/events`

#### Logs.

Connect to `ws://127.0.0.1:9503/log[/%level%]` to get logs in real time.

`%level%` is optional parameter to filter logs. 
If filter is specified, then only messages with equal or greater level will be send.
This endpoint will send only alert and emergency logs: `ws://127.0.0.1:9503/log/alert`

Available levels: debug, info, notice, warning, error, critical, alert, emergency.

PHP websocket client example: [websocket-events.php](https://github.com/xtrime-ru/TelegramApiServer/blob/master/examples/websocket-events.php)

`php examples/websocket-events.php --url=ws://127.0.0.1:9503/log`


## Contacts

* Telegram: [@xtrime](tg://resolve?domain=xtrime)
* Email: alexander(at)i-c-a.su
* Donations:
    * BTC: `1BE1nitXgEAxg7A5tgec67ucNryQwusoiP`
    * ETH: `0x0e2d369E28DCA2336803b9dE696eCDa50ff61e27`
