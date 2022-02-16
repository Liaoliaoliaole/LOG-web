###############################################################################
#               Makefile for Morfeas_web UI Documentation                     #
# Copyright (C) 12019-12022  Sam harry Tzavaras        	                      #
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

all: $(BUILD_dir)/Morfeas_NOX_if.pdf \
	 $(BUILD_dir)/Morfeas_MDAQ_if.pdf \
	 $(BUILD_dir)/Morfeas_IOBOX_if.pdf \
	 $(BUILD_dir)/Morfeas_configuration.pdf

#Compiling of the documents
$(BUILD_dir)/Morfeas_NOX_if.pdf: $(SRC_dir)/Morfeas_NOX_if/*.tex
	latexmk $(Latex_FLAGS) $(SRC_dir)/Morfeas_NOX_if/Morfeas_NOX_if.tex && mv $(WORK_dir)/Morfeas_NOX_if.pdf $(BUILD_dir)/
$(BUILD_dir)/Morfeas_MDAQ_if.pdf: $(SRC_dir)/Morfeas_MDAQ_if/*.tex
	latexmk $(Latex_FLAGS) $(SRC_dir)/Morfeas_MDAQ_if/Morfeas_MDAQ_if.tex && mv $(WORK_dir)/Morfeas_MDAQ_if.pdf $(BUILD_dir)/
$(BUILD_dir)/Morfeas_IOBOX_if.pdf: $(SRC_dir)/Morfeas_IOBOX_if/*.tex
	latexmk $(Latex_FLAGS) $(SRC_dir)/Morfeas_IOBOX_if/Morfeas_IOBOX_if.tex && mv $(WORK_dir)/Morfeas_IOBOX_if.pdf $(BUILD_dir)/
$(BUILD_dir)/Morfeas_configuration.pdf: $(SRC_dir)/Morfeas_configuration/*.tex
	latexmk $(Latex_FLAGS) $(SRC_dir)/Morfeas_configuration/Morfeas_configuration.tex && mv $(WORK_dir)/Morfeas_configuration.pdf $(BUILD_dir)/

tree:
	mkdir -p $(BUILD_dir) $(WORK_dir)

delete-the-tree:
	rm -f -r $(WORK_dir) $(BUILD_dir)

clean:
	rm -f $(WORK_dir)/* $(BUILD_dir)/*.pdf

.PHONY: all clean clean-tree tree


