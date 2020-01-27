# Morfeas Web

# Building the UI project

Pre-requisites:
- [NodeJS](https://nodejs.org/en/) and npm

1. Clone the repository and change directory to `morfeas-ui/`
2. Install the required dependencies by running `npm install`
3. Build the Angular project by running `npm run build-prod` inside the `morfeas-ui/` folder. The transpiled files will be in `morfeas-ui/dist/morfeas-web/` which can be then copied to the web server.

Additional useful commands:

`npm run start`, run the local development server
`npm run test`, run the unit tests. (Requires Chrome to be installed, as its using HeadlessChrome.)
`npm run lint`, run the linter

You can also install [Angular CLI](https://cli.angular.io/). Instructions to use that can be found in the README in `morfeas-ui/README.md`

# Running the backend

Pre-requisites
- python3
- python3-venv
- python3-pip

Python3 comes preinstalled with Debian. 

Instructions
1. Create virtualenv. In the repository root, run the following command `python3 -m venv ./morfeas/venv/`
2. Activate the virtual env with `source morfeas/venv/bin/activate`
3. Install wheel `pip install wheel`
4. Install dependencies with `pip install -r morfeas/requirements.txt`
5. Setup the `config.json` using the `config.template.json` (`cp morfeas/config.template.json morfeas/config.json`, adjust the contents as necessary)
6. Run the dev backend with `python morfeas/app.py`
7. Exit virtual env by issuing command `deactivate`