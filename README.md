# TelegramApiServer
Fast, simple, async php telegram api server: 
[MadelineProto](https://github.com/danog/MadelineProto) and [AmpPhp](https://github.com/amphp/amp) Server

* Online server for tests: [tg.i-c-a.su](https://tg.i-c-a.su)
* My content aggregator: [i-c-a.su](https://i-c-a.su)
* Im using this micro-service with: [my TelegramRSS micro-service](https://github.com/xtrime-ru/TelegramRSS) 

**Features**

* Fast async server
* Full access to telegram api: bot and user

**Architecture Example**
![Architecture Example](https://hsto.org/webt/j-/ob/ky/j-obkye1dv68ngsrgi12qevutra.png)
 
**Installation**

1. Git clone this repo
1. `composer install -o --no-dev` to install required libs
1. Create .env from .env.example
1. Fill variables in .env
1. Get app_id and app_hash at [my.telegram.org](https://my.telegram.org/) or leave blank.
   MadelineProto will generate them on start.

     _Optional:_
1. Use supervisor to monitor and restart swoole/amphp servers. Example of `/etc/supervisor/conf.d/telegram_api_server.conf`: 
     ```
    [program:telegram_api_server]
    command=/usr/bin/php /home/admin/web/tg.i-c-a.su/TelegramApiServer/server.php
    numprocs=1
    directory=/home/admin/web/tg.i-c-a.su/TelegramApiServer/
    autostart=true
    autorestart=true
    stdout_logfile=none
    redirect_stderr=true
     ```

**Usage**

1. Run server/parser
    ```
    php server.php [--help] [-a|--address=127.0.0.1] [-p|--port=9503]
    
    Options:
            --help      Show this message
        -a  --address   Server ip (optional) (example: 127.0.0.1)
        -p  --port      Server port (optional) (example: 9503)
        -s  --session   Prefix for session file (optional) (example: xtrime). 
                        Multiple sessions can be used via CombinedAPI. Example "--session=user --session=bot"
                        If running multiple sessions, then "session" parameter must be provided with every request.
    
    Also  options can be set in .env file (see .env.example)
    ```
1. Access telegram api directly via simple get requests.    
    Rules:
    * All methods from MadelineProto supported: [Methods List](https://docs.madelineproto.xyz/API_docs/methods/)
    * Url: `http://%address%:%port%/api/[%session%/]%class%.%method%/?%param1%=%val%`
    * <b>Important: api available only from ip in whitelist.</b> 
        By default it is: `127.0.0.1`
        You can add client ip in .env file to `API_CLIENT_WHITELIST` (use json format)
    * If method is inside class (messages, contacts and etc.) use '.' to separate class from method: 
        `http://127.0.0.1:9503/api/contacts.getContacts`
    * If method requires array of values, use any name of array, for example 'data': 
        `?data[peer]=@xtrime&data[message]=Hello!`. Order of parameters does't matter in this case.
    * If method requires one or multiple separate parameters (not inside array) then pass parameters with any names but **in strict order**: 
        `http://127.0.0.1:9503/api/getInfo/?id=@xtrime` or `http://127.0.0.1:9503/api/getInfo/?abcd=@xtrime` works the same
    * CombinedAPI (multiple sessions) support. 

        If running with multiple sessions use include 'session' in path, before method, to define which session to use for request:
        * `php server.php --session=session --session=bot --session=xtrime`
        * `http://127.0.0.1:9503/api/xtrime/getSelf` 
        * `http://127.0.0.1:9503/api/bot/getSelf`
        * `http://127.0.0.1:9503/api/session/getSelf`
        
        Each session is store in `{$session}.madeline` file in root folder of library.
    * EventHandler updates via websocket. Connect to `ws://127.0.0.1:9503/events`. You will get all events in json.
        Each event stored inside object, where key is name of session which created event. 
        
        When using CombinedAPI (multiple account) name of session can be added to path of websocket endpoint: 
        `ws://127.0.0.1:9503/events/session_name`. This endpoint will send events only from given session.
        
        PHP websocket client example: [websocket-events.php](https://github.com/xtrime-ru/TelegramApiServer/blob/master/examples/websocket-events.php)
    
    Examples:
    * get_info about channel/user: `http://127.0.0.1:9503/api/getInfo/?id=@xtrime`
    * get_info about currect account: `http://127.0.0.1:9503/api/getSelf`
    * repost: `http://127.0.0.1:9503/api/messages.forwardMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id]=1234`
    * get messages from channel/user: `http://127.0.0.1:9503/api/getHistory/?data[peer]=@breakingmash&data[limit]=10`
    * get messages with text in HTML: `http://127.0.0.1:9503/api/getHistoryHtml/?data[peer]=@breakingmash&data[limit]=10`
    * search: `http://127.0.0.1:9503/api/searchGlobal/?data[q]=Hello%20World&data[limit]=10`
    * sendMessage: `http://127.0.0.1:9503/api/sendMessage/?data[peer]=@xtrime&data[message]=Hello!`
    * copy message from one channel to other (not repost): `http://127.0.0.1:9503/api/copyMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id][0]=1`


**Contacts**

* Telegram: [@xtrime](tg://resolve?domain=xtrime)
* Email: alexander(at)i-c-a.su

**Donations**

* BTC: 1BE1nitXgEAxg7A5tgec67ucNryQwusoiP
* ETH: 0x0e2d369E28DCA2336803b9dE696eCDa50ff61e27
