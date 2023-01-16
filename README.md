# TelegramApiServer
Fast, simple, async php telegram api server: 
[MadelineProto](https://github.com/danog/MadelineProto) and [Amp](https://github.com/amphp/amp) Http Server

* Online demo (getHistory + Media Download): [tg.i-c-a.su](https://tg.i-c-a.su)
* My content aggregator: [i-c-a.su](https://i-c-a.su)
* Get telegram channels in RSS: [TelegramRSS](https://github.com/xtrime-ru/TelegramRSS) 

## Features

* Fast async Amp Http Server
* Full access to telegram api: bot and user
* Multiple sessions
* Stream media (view files in a browser)
* Upload media
* Websocket endpoints for events and logs
* MadelineProto optimized settings to reduce memory consumption

**Architecture Example**
![Architecture Example](https://hsto.org/webt/j-/ob/ky/j-obkye1dv68ngsrgi12qevutra.png)
 
## Installation

### Docker: 
1. `git clone https://github.com/xtrime-ru/TelegramApiServer.git TelegramApiServer`
1. `cd TelegramApiServer`
1. Start container: `docker-compose up`  
    Folder will be linked inside container to store all necessary data: sessions, env, db.

### Manual: 
1. Requirements: 
    * ssh / cli
    * php 8.1+
    * composer
    * git
    * Mysql/MariaDB (optional)
    * [MadelindeProto Requirements](https://docs.madelineproto.xyz/docs/REQUIREMENTS.html)
    * [Amp Requirements](https://github.com/amphp/amp#requirements)
    * XAMPP (for Windows)
    
1. `git clone https://github.com/xtrime-ru/TelegramApiServer.git TelegramApiServer`
1. `cd TelegramApiServer`
1. `composer install -o --no-dev`
1. `php server.php`

## First start
1. Ctrl + C to stop TelegramApiServer if running.
1. Get app_id and app_hash at [my.telegram.org](https://my.telegram.org/). 
    Only one app_id needed for any amount of users and bots.
1. Fill app_id and app_hash in `.env.docker` or `.env`.
1. Start TelegramApiServer in cli:
    * docker: 
        1. Start container interactively: `docker-compose run --rm telegram-api-server`
    * manual:
        1. `php server.php --session=session`
1. Authorize your session:
    1. Chose account type: user (`u`) or bot (`b`)
    1. Follow instructions
1. Wait 10-30 seconds until authorization is end and exit with `Ctrl + C`.
1. Run TAS in screen, tmux, supervisor (see below) or docker.

## Usage

1. Run server/parser
    ```
    usage: php server.php [--help] [-a=|--address=127.0.0.1] [-p=|--port=9503] [-s=|--session=]  [-e=|--env=.env] [--docker]
    
    Options:
            --help      Show this message
            
        -a  --address   Server ip (optional) (default: 127.0.0.1)
                        To listen external connections use 0.0.0.0 and fill IP_WHITELIST in .env
                        
        -p  --port      Server port (optional) (default: 9503)
        
        -s  --session   Name for session file (optional)
                        Multiple sessions can be specified: "--session=user --session=bot"
                        
                        Each session is stored in `sessions/{$session}.madeline`. 
                        Nested folders supported.
                        See README for more examples.
    
        -e  --env       .env file name. (default: .env). 
                        Helpful when need multiple instances with different settings
        
            --docker    Apply some settings for docker: add docker network to whitelist.
    
    Also some options can be set in .env file (see .env.example)
    ```
1. Access Telegram API with simple GET/POST requests.

    Regular and application/json POST supported.
    It's recommended to use http_build_query, when using GET requests.
    
    **Rules:**
    * All methods from MadelineProto supported: [Methods List](https://docs.madelineproto.xyz/API_docs/methods/)
    * Url: `http://%address%:%port%/api[/%session%]/%class%.%method%/?%param%=%val%`
    * <b>Important: api available only from ip in whitelist.</b> 
        By default it is: `127.0.0.1`
        You can add a client IP in .env file to `IP_WHITELIST` (separate with a comma)
        
        In docker version by default api available only from localhost (127.0.0.1).
        To allow connections from the internet, need to change ports in docker-compose.yml to `9503:9503` and recreate the container: `docker-compose up -d`. 
        This is very insecure, because this will open TAS port to anyone from the internet. 
        Only protection is the `IP_WHITELIST`, and there are no warranties that it will secure your accounts.
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

## Run in background
* Docker: `docker-compose up -d`
    Docker will monitor and restart containers.
* Manual: 
    1. Use [supervisor](http://supervisord.org) to monitor and restart swoole/amphp servers. 
    1. `apt-get install supervisor`
    1. Put config file in `/etc/supervisor/conf.d/telegram_api_server.conf`. Example: 
    ```
    [program:telegram_api_server]
    command=/usr/bin/php /home/admin/web/tg.i-c-a.su/TelegramApiServer/server.php --session=*
    numprocs=1
    directory=/home/admin/web/tg.i-c-a.su/TelegramApiServer/
    autostart=true
    autorestart=true
    startretries=10
    stdout_logfile=/var/log/telegram/stdout.log
    redirect_stderr=true
    ```
    1. Load new config: `supervisorctl update`
    1. View/control processes: `supervisorctl`
    
## Update
* `git pull` or `git fetch && git reset --hard origin/master`
* `composer install -o --no-dev`
* Compare `.env.docker` or `.env` with corresponding `.env.example`. Update if needed.
* Docker: 
    * `docker-compose pull`
    * `docker-compose down`
    * `docker-compose up`
* Manual: `supervisorctl restart telegram_api_server`
    
## Advanced features
### Uploading files.

There are few options to upload and send media files:
- Custom method `sendMedia` supports upload from form:
    ```shell script
    curl "http://127.0.0.1:9503/api/sendMedia?data[peer]=xtrime&data[message]=Hello" -g \
    -F "file=@/Users/xtrime/Downloads/test.txt"
    ```
- use custom `uploadMediaForm` method and then pass result to `messages.sendMedia`:
    1. `curl "http://127.0.0.1:9503/api/uploadMediaForm" -g -F "file=@/Users/xtrime/Downloads/test.txt"`
    Method supports `application/x-www-form-urlencoded` and `multipart/form-data`.
    
    2. Send result from `uploadMediaForm` to `messages.sendMedia` or `sendMedia`:
    ```shell script
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
- See other options: https://docs.madelineproto.xyz/docs/FILES.html#uploading-files

### Downloading files

```shell script
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
                "bytes": "AkKdqJkAACnyXshwzMhdzeC5RkdVZeh58sAB/UU="
            },
            "date": 1551713685,
            "mime_type": "video/mp4",
            "size": 400967,
            "dc_id": 2,
            "attributes": [
                {
                    "_": "documentAttributeFilename",
                    "file_name": "одолдол.mp4"
                }
            ]
        }
    }
}'
```

Also see: https://docs.madelineproto.xyz/docs/FILES.html#downloading-files

### Multiple sessions support
**WARNING: running multiple sessions in one instance is unstable.**
Crash/error in one session will crash all of them.
Correct way: override docker-compose.yml and add containers with different ports and session names for each session.

When running  multiple sessions, need to define which session to use for request.
Each session stored in `sessions/{$session}.madeline`. Nested folders supported.
**Examples:**
* `php server.php --session=bot --session=users/xtrime --session=users/user1`
* `http://127.0.0.1:9503/api/bot/getSelf`
* `http://127.0.0.1:9503/api/users/xtrime/getSelf` 
* `http://127.0.0.1:9503/api/users/user1/getSelf`
* sessions file paths are: `sessions/bot.madeline`, `sessions/users/xtrime.madeline` and `sessions/users/user1.madeline`
* glob syntax for sessions:
    * `--session=*` to use all `sessions/*.madeline` files (in subfolders too).
    * `--session=users/* --session=bots/*`  to use all session files from `sessions/bots` and `sessions/users` folders. 

### Different settings for sessions
* Use `--env` argument to define the relative path to env file.
    Example: ```php server.php --env=.env```, ```php server.php --env=sessions/.env.session```   
    This is helpful to define unique settings for different instances of TelegramApiServer.  
    You can start multiple instances of TelegramApiServer with different sessions on different ports with their own settings.

* Another way to manage settings - put %sessionName%.settings.json in sessions folder. 
    Example of `session.settings.json` to add proxy for the one session:

    ```json
    {
        "connection_settings": {
            "all": {
                "proxy": "\\SocksProxy",
                "proxy_extra": {
                    "address": "127.0.0.1",
                    "port": 1234,
                    "username": "user",
                    "password": "pass"
                }
            }
        }
    }
    ```
    Methods to work with settings files:
    * `http://127.0.0.1:9503/system/saveSessionSettings?session=session&settings[app_info][app_id]=xxx&settings[app_info][app_hash]=xxx`
    * `http://127.0.0.1:9503/system/unlinkSessionSettings?session=session`
* Provide settings as second argument when adding session: `http://127.0.0.1:9503/system/addSession?session=users/xtrime&settings[app_info][app_id]=xxx&&settings[app_info][app_hash]=xxx`
    These settings will be saved into json file and will apply after the restart. 

### Session management

**Examples:**
* Session list: `http://127.0.0.1:9503/system/getSessionList`
* Adding session: `http://127.0.0.1:9503/system/addSession?session=users/xtrime`
* Removing session (session file will remain): `http://127.0.0.1:9503/system/removeSession?session=users/xtrime`
  Due to madelineProto issue its instance still might be in memory and continue working even after the remove.
* Remove session file: `http://127.0.0.1:9503/system/unlinkSessionFile?session=users/xtrime`
    Don`t forget to logout and call removeSession first!
* Close TelegramApiServer (end process): `http://127.0.0.1:9503/system/exit`

Full list of system methods available in [SystemApiExtensions class](https://github.com/xtrime-ru/TelegramApiServer/blob/master/src/MadelineProtoExtensions/SystemApiExtensions.php)

### Authorizing session remotely
WARNING: it is recomended to use interactive mode to authorize sessions!
If there is no authorization in session, or session file is blank, authorization required:

User: 
* `http://127.0.0.1:9503/api/users/xtrime/phoneLogin?phone=%2B7123...`, %2B - is urlencoded "+" sign
* `http://127.0.0.1:9503/api/users/xtrime/completePhoneLogin?code=123456`
* (optional) `http://127.0.0.1:9503/api/users/xtrime/complete2falogin?password=123456`
* (optional) `http://127.0.0.1:9503/api/users/xtrime/completeSignup?firstName=MyExampleName`

Bot:
* `http://127.0.0.1:9503/api/bot/botLogin?token=34298141894:aflknsaflknLKNFS`

Save new session to file immediately: `http://127.0.0.1:9503/api/bot/serialize`

Also, session can be authorized in cli/shell on server start.

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


### Custom methods

TelegramApiServer extends madelineProto with some handful methods.   
Full list of custom methods and their parameters available in [ApiExtensions class](https://github.com/xtrime-ru/TelegramApiServer/blob/master/src/MadelineProtoExtensions/ApiExtensions.php#L19)

* `getHistory` - same as messages.getHistory, but all params exept peer is optional.
* `getHistoryHtml` - message entities converted to html
* `formatMessage` - converts entities to html
* `copyMessages` - copy message from one peer to onother. Like forwardMessages, but without the link to original.
* `getMedia` - download media to stream/browser
* `getMediaPreview` - download media preview to stream/browser
* `uploadMediaForm` - upload document from POST request.


## Contacts

* Telegram:   
    * Author: [@xtrime](https://t.me/xtrime)  
    * [MadelineProto and Amp Support Groups](https://t.me/pwrtelegramgroup)
* Email: alexander(at)i-c-a.su
