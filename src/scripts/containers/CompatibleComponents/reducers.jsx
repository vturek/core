import * as actions from './actions';
import { convertHashToArray, removeFromArray } from '../../core/helpers';


export default (state = {
	loaded: false,
	errorLoading: false,
	error: '',
	isEditing: false,
    showComponentInfoModal: false,
    componentInfoModalContent: '', // { type: 'module' | theme | api | core, folder: '' }
    componentChangelog: {}, // component_folder => { versions: [], isLoading: true|false }
	core: {},
	api: {},
	modules: {},
	themes: {},
    changelogs: {}, // populated on demand when a user clicks the About link for a component
    selectedComponentTypeSection: 'modules',
    selectedModuleFolders: [],
    selectedThemeFolders: [],
    apiSelected: false,

    // any time the user clicks "Customize" we stash the last config here, in case they cancel their changes and
    // want to revert
    lastSavedComponents: {}
}, action) => {

	switch (action.type) {

		// converts the list of modules and themes to an object with (unique) folder names as keys
        case actions.COMPATIBLE_COMPONENTS_LOADED:
			const modules = {};

			action.modules.forEach(({ name, desc, folder, repo, version }) => {
				modules[folder] = {
					name, desc, folder, repo,
					version: version.version,
                    type: 'module'
				};
			});

			const themes = {};
			action.themes.forEach(({ name, desc, folder, repo, version }) => {
				themes[folder] = {
					name, desc, folder, repo,
					version: version.version,
                    type: 'theme'
				};
			});

            let api = {};
            if (action.api.length) {
                api = {
                    name: 'API',
                    folder: 'api',
                    type: 'api',
                    version: action.api[0].version,
                    release_date: action.api[0].release_date
                };
            }

            // only preselect modules and themes that ARE in fact in the available module/theme list
            const preselected_modules = action.default_components.modules.filter((module) => modules.hasOwnProperty(module));
            const preselected_themes = action.default_components.themes.filter((theme) => themes.hasOwnProperty(theme));

			return Object.assign({}, state, {
				loaded: true,
				modules,
				themes,
                api,
                apiSelected: action.default_components.api,
                selectedModuleFolders: preselected_modules,
				selectedThemeFolders: preselected_themes
			});

		case actions.TOGGLE_API:
            return {
                ...state,
                apiSelected: !state.apiSelected
            };

		case actions.TOGGLE_MODULE:
			return {
				...state,
				selectedModuleFolders: selectedComponentsReducer(state.selectedModuleFolders, action.folder)
			};

		case actions.TOGGLE_THEME:
			return {
				...state,
				selectedThemeFolders: selectedComponentsReducer(state.selectedThemeFolders, action.folder)
			};

        case actions.SELECT_ALL_MODULES:
            return {
                ...state,
                selectedModuleFolders: convertHashToArray(state.modules).map((module) => module.folder)
            };

        case actions.DESELECT_ALL_MODULES:
            return {
                ...state,
                selectedModuleFolders: []
            };

		case actions.EDIT_SELECTED_COMPONENT_LIST:
			return {
				...state,
				isEditing: true,
                lastSavedComponents: {
				    selectedModuleFolders: state.selectedModuleFolders,
                    selectedThemeFolders: state.selectedThemeFolders,
                    apiSelected: state.apiSelected
                }
			};

		case actions.CANCEL_EDIT_SELECTED_COMPONENT_LIST:
			return {
				...state,
				isEditing: false,
                selectedModuleFolders: state.lastSavedComponents.selectedModuleFolders,
                selectedThemeFolders: state.lastSavedComponents.selectedThemeFolders,
                apiSelected: state.lastSavedComponents.apiSelected
			};

        case actions.SAVE_SELECTED_COMPONENT_LIST:
            return {
                ...state,
                isEditing: false
            };

        case actions.SELECT_COMPONENT_TYPE_SECTION:
            return {
                ...state,
                selectedComponentTypeSection: action.section
            };

        case actions.SHOW_COMPONENT_CHANGELOG_MODAL:
            return {
                ...state,
                showComponentInfoModal: true,
                componentInfoModalContent: {
                    componentType: action.payload.componentType,
                    folder: action.payload.folder
                }
            };

        case actions.CLOSE_COMPONENT_CHANGELOG_MODAL:
            return {
                ...state,
                showComponentInfoModal: false
            };

        case actions.COMPONENT_HISTORY_LOADED:
            const updatedChangelogs = { ...state.changelogs };
            updatedChangelogs[action.payload.folder] = action.payload.versions;

            const newState = {
                ...state,
                changelogs: updatedChangelogs
            };

            if (action.payload.folder === 'core') {
                newState.core = { desc: action.payload.desc };
            } else if (action.payload.folder === 'api') {
                newState.api.desc = action.payload.desc;
            }
            return newState;
	}


	return state;
};

const selectedComponentsReducer = (state = [], folder) => {
    if (state.includes(folder)) {
        return removeFromArray(state, folder);
    } else {
        return [...state, folder];
    }
};