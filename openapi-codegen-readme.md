## Download swagger-codegen binary

```bash
wget https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/3.0.22/swagger-codegen-cli-3.0.22.jar -O swagger-codegen-cli.jar
```


## Generate single open api yaml file

```bash
docker run --rm -v ${PWD}:/local -w /local openjdk java -jar swagger-codegen-cli.jar generate \
    --input-spec /local/openapi.yaml --lang openapi-yaml --output /local/out/ -c /local/openapi-codegen-php.json
```

## Generate PHP client library

```bash
docker run --rm -v ${PWD}:/local -w /local openjdk java -jar swagger-codegen-cli.jar generate \
    --input-spec /local/openapi.yaml --lang php --output /local/out/ -c /local/openapi-codegen-php.json
```

### Use custom codegenerator templates

`--template-dir /local/php/`

## Generate other

docker run --rm -v ${PWD}:/local -w /local openjdk java -jar swagger-codegen-cli.jar generate \
    --input-spec /local/openapi.yaml --lang dynamic-html --output /local/out/ -c /local/openapi-codegen-php.json

docker run --rm -v ${PWD}:/local -w /local openjdk java -jar swagger-codegen-cli.jar generate \
    --input-spec /local/openapi.yaml --lang html --output /local/out/ -c /local/openapi-codegen-php.json




docker run --rm \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i /local/openapi.yaml \
    -g asciidoc \
    -o /local/out/asciidoc


docker run --rm \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i /local/openapi.yaml \
    -g dynamic-html \
    -o /local/out/dynamic-html


docker run --rm \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i /local/openapi.yaml \
    -g html2 \
    -o /local/out/html2


docker run --rm \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i /local/openapi.yaml \
    -g openapi \
    -o /local/out/openapi


docker run --rm \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i /local/openapi.yaml \
    -g openapi-yaml \
    -o /local/out/openapi-yaml


    docker run --rm \
    --network host \
    -v $PWD:/local \
    openapitools/openapi-generator-cli \
    generate \
    -i https://docs.api2.dev.hoqu.com/docs/api.yml \
    -g openapi-yaml \
    -o /local/out/hoq0-openapi-yaml


Swagger-Codegen/1.10.4/php

