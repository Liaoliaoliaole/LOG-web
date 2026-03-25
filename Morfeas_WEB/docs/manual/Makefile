SRC_DIR=src
WORK_DIR=work
OUT_PDF=../help_manual.pdf
MAIN_TEX=$(SRC_DIR)/help_manual.tex

all: $(OUT_PDF)

$(OUT_PDF): $(MAIN_TEX) $(SRC_DIR)/*.tex
	mkdir -p $(WORK_DIR)
	pdflatex -interaction=nonstopmode -output-directory=$(WORK_DIR) $(MAIN_TEX)
	pdflatex -interaction=nonstopmode -output-directory=$(WORK_DIR) $(MAIN_TEX)
	cp $(WORK_DIR)/help_manual.pdf $(OUT_PDF)

clean:
	rm -rf $(WORK_DIR)

distclean: clean
	rm -f $(OUT_PDF)

.PHONY: all clean distclean
