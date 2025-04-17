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

```shell
git clone https://github.com/xtrime-ru/TelegramApiServer.git TelegramApiServer
cd TelegramApiServer
cp .env.docker.example .env.docker
docker compose pull
```

## Authorization
Please only use old and valid accounts. All new accounts will be banned by telegram.
If your account was banned read this: https://docs.madelineproto.xyz/docs/LOGIN.html#getting-permission-to-use-the-telegram-api
1. Get app_id and app_hash at [my.telegram.org](https://my.telegram.org/). 
    Only one app_id needed for any amount of users and bots.
1. Fill app_id and app_hash in `.env.docker`.
1. Start TelegramApiServer in cli:
        1. Start container interactively: `docker compose run --rm api`
        2. If you need to start multiple sessions, create docker-compose.override.yml. Add additional containers there. Use unique ports and session names in `command`.
1. Authorize your session:
    1. After promt, fill your phone number, or bot hash.
    1. You will receive telegram code. Type it in.
       If you're not receiving code - your server IP or hosting may be blocked by telegram. 
       Try another server or change server IP.
    1. If you have 2fa enabled - enter 2fa passord.
1. Wait 10-30 seconds until session is started.
   You will see logs:
   ```text
   TelegramApiServer ready. 
   Number of sessions: 1.
   ```
1. Exit with `Ctrl + C` 
1. Run container in background `docker compose up -d`.

## Update
* `git pull` or `git fetch && git reset --hard origin/master`
* `rm -rf vendor/`
* Compare `.env.docker` or `.env` with corresponding `.env.example`. Update if needed.
* Recreate containers:
  ```shell
  docker compose pull
  docker compose down
  docker compose up -d
  ```

## Security
Please be careful with settings, otherwise you can expose your telegram session and lose control.
Default settings allow to access API only from localhost/127.0.0.1.

.env settings:
- `IP_WHITELIST` - allow specific IP's to make requests without password.
- `PASSWORDS` - protect your api with basic auth.  
  Request with correct username and password overrides IP_WHITELIST.
  If you specify password, then `IP_WHITELIST` is ignored
  How to make requests with basic auth: 
  ```shell
  curl --user username:password "http://127.0.0.1:9503/getSelf"
  curl "http://username:password@127.0.0.1:9503/getSelf"
  ```

docker-compose.yml:
- `port` - port forwarding rules from host to docker container.
  Remove 127.0.0.1 to listen all interfaces and forward all requests to container.
  Make sure to use IP_WHITELIST and/or PASSWORDS settings to protect your account.

## Usage
Access Telegram API with simple GET/POST requests.
Regular and application/json POST supported.
It's recommended to use http_build_query, when using GET requests.
    
**Rules:**
* All methods from MadelineProto supported: [Method List](https://docs.madelineproto.xyz/API_docs/methods/)
* Url: `http://%address%:%port%/api[/%session%]/%class%.%method%/?%param%=%val%`
* <b>Important: api available only from ip in whitelist.</b> 
    By default it is: `127.0.0.1`
    You can add a client IP in .env file to `IP_WHITELIST` (separate with a comma)
    
    In docker version by default api available only from localhost (127.0.0.1).
    To allow connections from the internet, need to change ports in docker-compose.yml to `9503:9503` and recreate the container: `docker compose up -d`. 
    This is very insecure, because this will open TAS port to anyone from the internet. 
    Only protection is the `IP_WHITELIST`, and there are no warranties that it will secure your accounts.
* If method is inside class (messages, contacts and etc.) use '.' to separate class from method: 
    `http://127.0.0.1:9503/api/contacts.getContacts`
* When passing files in POST forms, they must always come **last** in the field list, and all fields after the file will be ignored.

**Examples:**
* get_info about channel/user: `http://127.0.0.1:9503/api/getInfo/?id=@xtrime`
* get_info about currect account: `http://127.0.0.1:9503/api/getSelf`
* repost: `http://127.0.0.1:9503/api/messages.forwardMessages/?from_peer=@xtrime&to_peer=@xtrime&id=1234`
* get messages from channel/user: `http://127.0.0.1:9503/api/messages.getHistory/?peer=@breakingmash&limit=10`
* get messages with text in HTML: `http://127.0.0.1:9503/api/getHistoryHtml/?peer=@breakingmash&limit=10`
* search: `http://127.0.0.1:9503/api/searchGlobal/?q=Hello%20World&limit=10`
* sendMessage: `http://127.0.0.1:9503/api/messages.sendMessage/?peer=@xtrime&message=Hello!`
* copy message from one channel to another (not repost): `http://127.0.0.1:9503/api/copyMessages/?from_peer=@xtrime&to_peer=@xtrime&id[0]=1`
    
## Advanced features
### Get events/updates
Telegram is event driven platform. For example:  every time your account receives a message you immediately get an update.
There are multiple ways of [getting updates](https://docs.madelineproto.xyz/docs/UPDATES.html) in TelegramApiServer / MadelineProto:  
1. [Websocket](#eventhandler-updates-webhooks)  
2. Long Polling:   
send request to getUpdates endpoint  
`curl "127.0.0.1:9503/api/getUpdates?limit=3&offset=0&timeout=10.0" -g`  
3. Webhook:
Redirect all updates to your endpoint, just like bot api!  
`curl "127.0.0.1:9503/api/setWebhook?url=http%3A%2F%2Fexample.com%2Fsome_webhook" -g `  
Example uses urlencoded url in query.

### Uploading files.

There are few options to upload and send media files:

- Custom method `sendDocument`/`sendVideo`/etc ([full list here](https://docs.madelineproto.xyz/docs/FILES.html)) to send document/video/audio/voice/etc by url or local path, remote url, or stream.
  Stream upload from client:
  ```shell script
    curl --location --request POST 'http://127.0.0.1:9503/api/sendDocument' -g \
    -F peer=me \
    -F caption=key
    -F file=@screenshot.png \
    ```
  RemoteUrl:
    ```shell script
    curl --location --request POST 'http://127.0.0.1:9503/api/sendVideo' \
    --header 'Content-Type: application/json' \
    --data-raw '{
        "peer": "me",
        "file": {
            "_": "RemoteUrl",
            "url": "https://domain.site/storage/video.mp4"
        },
        "parseMode": "HTML",
        "caption": "<b>caption text</b>"
    }'
    ```
  Local file on server:
   ```shell script
    curl --location --request POST 'http://127.0.0.1:9503/api/sendDocument' \
    --header 'Content-Type: application/json' \
    --data-raw '{
        "peer": "me",
        "file": {
            "_": "LocalUrl",
            "file": "faust.txt"
        },
        "parseMode":  "HTML",
        "caption": "<b>caption text</b>"
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
Its recommended to run every session in separate container. 

To add more containers create `docker-compose.override.yml` file.
Docker will [automatically merge](https://docs.docker.com/compose/multiple-compose-files/merge/) it with default docker-compose file.

Example of `docker-compose.override.yml` with two additional containers/sessions (3 in total):
```yaml
services:
    api-2:
        extends:
            file: docker-compose.base.yml
            service: base-api
        ports:
            - "127.0.0.1:9512:9503"
        command:
            - "-s=session-2"
    api-3:
        extends:
            file: docker-compose.base.yml
            service: base-api
        ports:
            - "127.0.0.1:9513:9503"
        command:
            - "-s=session-3"

```
### Multiple sessions in one container (deprecated)
**WARNING: running multiple sessions in one instance/container is unstable.**
Crash/error in one session will crash all of them.

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
        "connection": {
            "proxies": {
                "\\danog\\MadelineProto\\Stream\\Proxy\\SocksProxy": [
                    {
                      "address": "127.0.0.1",
                      "port": 1234,
                      "username": "user",
                      "password": "pass"
                    }
                ],
                "\\danog\\MadelineProto\\Stream\\Proxy\\HttpProxy": [
                    {
                      "address": "127.0.0.1",
                      "port": 1234,
                      "username": "user",
                      "password": "pass"
                    }
                ]
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
* ~~Removing session (session file will remain): `http://127.0.0.1:9503/system/removeSession?session=users/xtrime`
  Due to madelineProto issue its instance still might be in memory and continue working even after the remove.~~
* ~~Remove session file: `http://127.0.0.1:9503/system/unlinkSessionFile?session=users/xtrime`
    Don`t forget to logout and call removeSession first!~~
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
    * Use madelineProto support groups to get support for TelegramApiServer.
* Email: alexander(at)i-c-a.su
