cd /home/lotus/PROJETOS/pcg729
bin/spc del-download psampler
rm -rf source/php-src/ext/psampler
bin/spc download psampler
bin/spc build --build-cli "swoole,psampler" --debug --enable-zts
cp buildroot/bin/php /home/lotus/PROJETOS/psampler