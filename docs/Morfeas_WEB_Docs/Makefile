###############################################################################
#                Makefile for Morfeas_web Documentation                      #
# Copyright (C) 12019-12020  Sam harry Tzavaras        	                      #
#                                                                             #
# This program is free software; you can redistribute it and/or               #
#  modify it under the terms of the GNU General Public License                #
#  as published by the Free Software Foundation; either version 3             #
#  of the License, or any later version.                                      #
#                                                                             #
# This program is distributed in the hope that it will be useful,             #
#  but WITHOUT ANY WARRANTY; without even the implied warranty of             #
#  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the              #
#  GNU General Public License for more details.                               #
#                                                                             #
# You should have received a copy of the GNU General Public License           #
# along with this program; If not, see <https://www.gnu.org/licenses/>.       #
###############################################################################
SRC_dir=src
BUILD_dir=build-doc
WORK_dir=work-doc
Latex_FLAGS=-pdf -pdflatex="pdflatex -interaction=nonstopmode" -use-make -outdir=$(WORK_dir)

all: $(BUILD_dir)/Reference_guide.pdf

#Compiling of the document
$(BUILD_dir)/Reference_guide.pdf: $(SRC_dir)/*.tex
	latexmk $(Latex_FLAGS) $(SRC_dir)/Reference_guide.tex && mv $(WORK_dir)/*.pdf $(BUILD_dir)/

tree:
	mkdir -p $(BUILD_dir) $(WORK_dir)

delete-the-tree:
	rm -f -r $(WORK_dir) $(BUILD_dir)

clean:
	rm -f $(WORK_dir)/* $(BUILD_dir)/*

.PHONY: all clean clean-tree tree


