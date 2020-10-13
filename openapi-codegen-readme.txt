wget https://repo1.maven.org/maven2/io/swagger/codegen/v3/swagger-codegen-cli/3.0.22/swagger-codegen-cli-3.0.22.jar -O swagger-codegen-cli.jar

docker run --rm -v ${PWD}:/local -w /local openjdk java -jar swagger-codegen-cli.jar generate --input-spec /local/openapi.yaml --lang php --output /local/out/ -c /local/openapi-codegen-php.json
