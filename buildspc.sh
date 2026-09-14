#!/bin/bash

cd /home/lotus/CLionProjects/pcg729/
# bin/spc del-download psampler
rm -rf source/php-src/ext/psampler
# bin/spc download psampler
cp -r /home/lotus/projetos/psampler /home/lotus/CLionProjects/pcg729/downloads
# swoole precisa da extensão pgsql para ter disponivel a libpq
bin/spc build --build-cli "swoole,ctype,standard,filter,pgsql,psampler" --no-strip --debug
cp buildroot/bin/php /home/lotus/projetos/psampler