# TelegramApiServer
Fast, simple, async php telegram client and parser: 
[MadelineProto](https://github.com/danog/MadelineProto) and [Swoole](https://github.com/swoole/swoole-src) Server

* Online server for tests: [tg.i-c-a.su](https://tg.i-c-a.su)
* My content aggregator: [i-c-a.su](https://i-c-a.su)
* Im using this micro-service with: [my TelegramRSS micro-service](https://github.com/xtrime-ru/TelegramRSS) 

**Features**

* Fast async swoole server
* Full access to telegram api: bot and user

**Example Architecture**
![Proposed Architecture](https://habrastorage.org/webt/qz/ax/ct/qzaxctkgwehhhsqglgszy4rowwa.png)
 
**Installation**

1. Swoole extension required: [Install swoole](https://github.com/swoole/swoole-src#%EF%B8%8F-installation)
1. `composer install` to install required libs
1. Create .env from .env.example
1. Fill variables in .env
1. Fill app_id and app_hash from [my.telegram.org](https://my.telegram.org/)
   Or leave blank and MadelineProto will try to generate on start. 

     _Optional:_
1. Use supervisor to monitor and restart swoole servers. Example of `/etc/supervisor/conf.d/telegram_rss.conf`: 
     ```
    [program:telegram_client]
    command=/usr/bin/php /home/admin/web/tg.i-c-a.su/TelegramSwooleClient/server.php
    numprocs=1
    directory=/home/admin/web/tg.i-c-a.su/TelegramSwooleClient/
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
        -s  --session   Prefix for session file (optional) (example: xtrime)
    
    Also  options can be set in .env file (see .env.example)
    ```
1. Access telegram api directly via simple get requests.    
    Rules:
    * All methods from MadelineProto supported: [Methods List](https://docs.madelineproto.xyz/API_docs/methods/)
    * Url: `http://%address%:%port%/api/%class%.%method%/?%param1%=%val%`
    * <b>Important: api available only from ip in whitelist.</b> 
        By default it is: `127.0.0.1`
        You can add client ip in .env file to `API_CLIENT_WHITELIST` (use json format)
    * If method is inside class (messages, contacts and etc.) use '.' to separate class from method: 
        `http://127.0.0.1:9503/api/contacts.getContacts`
    * If method requires array of values, use any name of array, for example 'data': 
        `?data[peer]=@xtrime&data[message]=Hello!`. Order of parameters does't matter in this case.
    * If method requires one or multiple separate parameters (not inside array) then pass parameters with any names but **in strict order**: 
        `http://127.0.0.1:9503/api/get_info/?id=@xtrime` or `http://127.0.0.1:9503/api/get_info/?abcd=@xtrime` works the same
    
    Examples:
    * get_info about channel/user: `http://127.0.0.1:9503/api/get_info/?id=@xtrime`
    * get_info about currect account: `http://127.0.0.1:9503/api/get_self`
    * repost: `http://127.0.0.1:9503/api/messages.forwardMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id]=1234`
    * get messages from channel/user: `http://127.0.0.1:9503/api/getHistory/?data[peer]=@breakingmash&data[limit]=10`
    * search: `http://127.0.0.1:9503/api/searchGlobal/?data[q]=Hello%20World&data[limit]=10`
    * sendMessage: `http://127.0.0.1:9503/api/sendMessage/?data[peer]=@xtrime&data[message]=Hello!`
    * copy message from one channel to other (not repost): `http://127.0.0.1:9503/api/copyMessages/?data[from_peer]=@xtrime&data[to_peer]=@xtrime&data[id][0]=1`
    
        
**Contacts**

* Telegram: [@xtrime](tg://resolve?domain=xtrime)
* Email: alexander(at)i-c-a.su

**Donations**

* BTC: 1BE1nitXgEAxg7A5tgec67ucNryQwusoiP
* ETH: 0x0e2d369E28DCA2336803b9dE696eCDa50ff61e27
