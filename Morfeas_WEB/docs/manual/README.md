# LOG WEB Help Manual Source

This directory contains the maintained source for the user help manual of the current LOG WEB application.

`Morfeas_WEB` remains in some repository paths for code compatibility and legacy deployment continuity.

## Output

The generated PDF is written to:

- `../help_manual.pdf`

This is the file linked by the Help menu inside the web application.

## Build

Requirements:

- `pdflatex`

Build the PDF:

```bash
make
```

Clean intermediate files:

```bash
make clean
```

Remove generated output and intermediates:

```bash
make distclean
```

## Scope

This manual is for the current new LOG WEB UI.

Legacy documentation under `LOG-web/Docs/Morfeas_WEB_Docs` is kept only as archived reference and should not be treated as the active user manual.
