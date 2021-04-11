export const environment = {
  production: true,
  ROOT: window.location.origin,
  API_ROOT: window.location.origin + '/morfeas_php/morfeas_web_if.php',
  UPDATE_ROOT: window.location.origin + '/morfeas_php/update_web_core.php',
  // this will be set when fetching the log list, which should happen before attempting to open any log
  LOG_PATH: ''
};
 