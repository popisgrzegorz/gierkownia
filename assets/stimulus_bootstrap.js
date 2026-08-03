import { startStimulusApp } from '@symfony/stimulus-bundle';

// Wszystkie kontrolery rejestrują się same przez assets/controllers.json -
// zmienna zostaje niewykorzystana jako gotowy hak pod przyszłą rejestrację
// kontrolera zewnętrznej biblioteki (patrz przykład niżej).
// eslint-disable-next-line no-unused-vars
const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
