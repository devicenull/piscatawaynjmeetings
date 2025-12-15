#!/bin/bash

INPUT="$1"

if [ ! -f "$INPUT" ]; then
	echo missing input file arg
	exit 1
fi

echo tmp.$$
mkdir tmp.$$
cd tmp.$$

pdfimages -j "../$1" img

for i in `find ./ -iname \*.jpg`; do
	convert $i -deskew 25% $i.deskew
	OUT=`echo $i | sed 's/.jpg//'`
	tesseract $i.deskew $OUT --psm 6 -c preserve_interword_spaces=1
	../parse_txt.py $OUT.txt
exit 0
done
