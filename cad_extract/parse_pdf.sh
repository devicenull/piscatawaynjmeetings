#!/bin/bash

INPUT="$1"

if [ ! -f "$INPUT" ]; then
	echo missing input file arg
	exit 1
fi

TESSDATA=`pwd`

echo tmp.$$
mkdir tmp.$$
cd tmp.$$

convert -density 300 "../$1" test.png
#pdfimages -j "../$1" img

for i in `find ./ -iname \*.png`; do
	#convert $i -deskew 25% $i.deskew
	OUT=`echo $i | sed 's/.png//'`
	# tessdata is from https://github.com/tesseract-ocr/tessdata_best/blob/main/eng.traineddata
	tesseract $i $OUT --psm 6 --oem 1 -c preserve_interword_spaces=1  --tessdata-dir $TESSDATA -l eng
	../parse_txt.py $OUT.txt
done
