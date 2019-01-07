# TelegramSwooleClient
Fast, simple, async php telegram client and parser: MadelineProto + Swoole Server

* Online server for tests (previous version, different request syntax): http://tg.i-c-a.su/?format=json&images=0&url=breakingmash
* My content aggregator: [https://i-c-a.su](https://i-c-a.su)

**Features**
 * Fast async swoole server
 * Use as micro-service
 * Get any public telegram posts from groups as json
 
**TODO**
* RSS output
* flood protection (for use in public)
* logging

**Installation**

1. Get app_id and app_hash at [my.telegram.org](https://my.telegram.org/)
1. Swoole extension required: [Install swoole](https://github.com/swoole/swoole-src#%EF%B8%8F-installation)
1. Install this package:

    a. Standalone: 
   
    1. download files from github and extract. 
    2. Run `composer install` inside unpacked directory
    
    b. Existing project: 
    
    1. Add following into your project's composer.json
    ```
    "repositories": [
        {
           "type": "git",
           "url": "https://github.com/xtrime-ru/TelegramSwooleClient.git"
        }
    ],
    "require": {
        "xtrime-ru/telegramswooleclient": "dev-master",
    }
    ```

**Usage**

1. Install
1. Fill options in .env file (see .env.example)
1. Run server/parser
    ```
    php server.php [--help] [-a|--address=127.0.0.1] [-p|--port=9503]
    
    Options:
    
            --help      Show this message
        -a  --address   Server ip (optional) (example: 127.0.0.1 or 0.0.0.0 to listen all hosts)
        -p  --port      Server port (optional) (example: 9503)
    
    Also all options can be set in .env file (see .env.example)
    ```
1. Get posts from any open channel

    * Get 10 latests posts from any open channel via GET request: 
        `http://%address%:%port%/json/%channel%`
        Example:
        `http://127.0.0.1:9503/json/breakingmash`
    * Get posts from multiple channels via POST:
        
        Url: `http://127.0.0.1:9503/json/`
        
        Headers: `Content-Type: application/json`

        `form-data` and `x-www-form-urlencoded` should work, 
        but was not tested
        
        Body:
        ```
        {
           	"getHistory": [
           		{
           		    "peer":"channel#1259060275"
           		}, 
           		{
           		    "peer": "breakingmash",
           		    "limit": 30,
                    "max_id": 200,
           		}
           	]
           	
           }
        ```
        You can use any other options from https://docs.madelineproto.xyz/API_docs/methods/messages_getHistory.html
        peer name can be provided in different formats: https://docs.madelineproto.xyz/API_docs/types/InputPeer.html
    
        
**Contacts**

* Telegram: [@xtrime](tg://resolve?domain=xtrime)
* Email: alexander(at)i-c-a.su
