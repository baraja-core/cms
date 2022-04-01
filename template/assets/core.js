console.log('%c Welcome to Baraja CMS. All components has been loaded successfully.', 'background:#3c3c3c;color:#bada55');

Vue.component('cms-menu', {
	props: ['structure', 'activeKey', 'dashboardLink', 'isDashboard', 'debugMode'],
	template: `<div class="cms-menu-container">
		<a :class="{'cms-menu-item': true, 'cms-menu-item-selected': isDashboard}" :href="dashboardLink">
			<b-icon icon="compass" class="mr-2"></b-icon>Dashboard
		</a>
		<template v-for="item in structure">
			<cms-menu-item :item-key="item.key" :title="item.title" :link="item.link" :icon="item.icon" :child="item.child" :priority="item.priority" :active-key="activeKey" :debug-mode="debugMode"></cms-menu-item>
		</template>
	</div>`
});

Vue.component('cms-menu-item', {
	props: ['itemKey', 'title', 'link', 'icon', 'child', 'priority', 'activeKey', 'debugMode'],
	template: `<a :class="{'cms-menu-item': true, 'cms-menu-item-selected': itemKey === activeKey}" :href="basePath + '/' + link">
	<span v-if="debugMode" class="text-secondary" style="font-size:10pt">[{{ priority }}]</span>
	<b-icon :icon="icon ? icon : 'hash'" class="mr-2"></b-icon>{{ title }}
	<div class="cms-menu-item" v-if="child.length > 0">
		<template v-for="item in child">
			<cms-menu-item :item-key="item.key" :title="item.title" :link="item.link" :icon="item.icon" :child="item.child" :priority="item.priority" :debug-mode="debugMode"></cms-menu-item>
		</template>
	</div>
</a>`,
	data() {
		return {
			basePath: ''
		}
	},
	mounted() {
		this.basePath = basePath;
	}
});

Vue.component('cms-footer', {
	props: ['year', 'version'],
	template: `<footer role="contentinfo">
	<div class="cms-footer">
		<div class="container" role="contentinfo">
			<div class="row">
				<div class="col-sm-7">
					<p>
						<a href="https://baraja.cz/cms" target="_blank">
							&lt;/BRJ&gt;
						</a>
						©&nbsp;2009-{{ year }}
						|&nbsp;<a href="https://brj.cz/help" target="_blank">Help</a>
						|&nbsp;<a href="https://brj.cz/support" target="_blank">Support</a>
					</p>
					<p class="pb-0 mb-0">
						<span class="text-secondary">{{ version }}</span>
					</p>
				</div>
				<div class="col text-right">
					<p>
						<a href="https://baraja.cz/vseobecne-obchodni-podminky" target="_blank">Terms</a>
						|&nbsp;<a href="https://baraja.cz/gdpr-ochrana-a-zpracovani-osobnich-udaju" target="_blank">Privacy</a>
					</p>
				</div>
			</div>
		</div>
		<div class="cms-footer__flag">
			<div class="cms-footer__flag-container">
				<div class="flag__red"></div>
				<div class="flag__orange"></div>
				<div class="flag__yellow"></div>
				<div class="flag__green"></div>
				<div class="flag__blue"></div>
				<div class="flag__purple"></div>
			</div>
		</div>
	</div>
</footer>`
});

Vue.component('cms-settings', {
	template: `<b-modal id="modal-cms-settings" size="lg" hide-header hide-footer body-class="p-0">
	<div>
		<div class="my-4 text-center">Settings is coming soon.</div>
	</div>
</b-modal>`,
	mounted() {
		eventBus.$on('cms-settings-open', () => {
			this.$bvModal.show('modal-cms-settings');
		});
	}
});

Vue.component('cms-card', {
	template: `<div class="card p-3 cms-card">
	<slot></slot>
</div>`
});

Vue.component('cms-filter', {
	template: `<div class="card" style="border-bottom:0 !important">
	<div class="card-header py-2">
		<slot></slot>
	</div>
</div>`
});

Vue.component('breadcrumb', {
	props: ['items'],
	template: `<b-breadcrumb>
		<template v-for="(item, key) in items">
			<b-breadcrumb-item :href="item.href" :active="key === items.length - 1">
				<i v-if="key === 0" class="fa fa-home"></i>
				{{ item.label }}
			</b-breadcrumb-item>
		</template>
	</b-breadcrumb>`,
});

Vue.component('cms-select', {
	props: ['label', 'values'],
	template: `<div :class="{'cms-select': true, 'cms-select-active': value !== null}">
		<table @click="changeOpenState(true)" class="w-100 p-0 m-0">
			<tr>
				<td>
					<template v-if="value === null">{{ selectedText }}</template>
					<b v-else>{{ selectedText }}</b>
				</td>
				<td class="text-right">
					<b-icon icon="chevron-down"></b-icon>
				</td>
			</tr>
		</table>
		<div v-if="open === true" class="cms-select-items">
			<div class="cms-select-item" @click="selectValue(null, label)">{{ label }}</div>
			<template v-for="valueItem in values">
				<div v-if="valueItem.text !== undefined" class="cms-select-item" @click="selectValue(valueItem.value, valueItem.text)">
					<template v-if="valueItem.value !== value">{{ valueItem.text }}</template>
					<b v-else>{{ valueItem.text }}</b>
				</div>
			</template>
		</div>
	</div>`,
	data() {
		return {
			value: null,
			selectedText: null,
			open: false
		}
	},
	mounted() {
		eventBus.$on('cms-select-open', () => {
			this.open = false;
		});
	},
	created() {
		this.$nextTick(() => {
			if (this.label) {
				this.value = null;
				this.selectedText = this.label;
			} else if (this.values.length > 0) {
				this.value = this.values[0].value;
				this.selectedText = this.values[0].text;
			}
		});
	},
	methods: {
		changeOpenState(open) {
			if (open === true) {
				eventBus.$emit('cms-select-open');
			}
			this.open = open;
		},
		selectValue(value, label) {
			this.value = value;
			this.selectedText = label;
			this.changeOpenState(false);
			this.$emit('value', value);
		}
	}
});

Vue.component('cms-global-search', {
	template: `<table class="global-search" :style="'width:' + (query === '' ? 180 : 400) + 'px !important'">
	<tr>
		<td style="padding:0 8px !important">
			<input type="search" v-model="query" placeholder="Search...">
			<div v-if="query" class="autocomplete">
				<div v-if="autocomplete === null" class="autocomplete-spinner">
					<b-spinner small></b-spinner>
				</div>
				<div v-else class="autocomplete-body">
					<div v-for="autocompleteItem in autocomplete" class="search-result-item">
						<a :href="autocompleteItem.link">
							<span v-html="autocompleteItem.title"></span>
							<span v-if="autocompleteItem.snippet" v-html="autocompleteItem.snippet" class="search-result-snippet"></span>
						</a>
					</div>
				</div>
			</div>
		</td>
	</tr>
</table>`,
	data() {
		return {
			query: '',
			autocomplete: null
		}
	},
	watch: {
		query: function() {
			if (this.query === '') {
				this.autocomplete = null;
				return;
			}
			axiosApi.get('cms-global-search?' + httpBuildQuery({
				query: this.query
			})).then(req => {
				if (req.data.query === this.query) {
					this.autocomplete = req.data.results;
				}
			});
		}
	}
});

Vue.component('cms-search', {
	props: ['placeholder'],
	template: `<div :class="{'cms-search': true, 'cms-search-focus': focus}">
		<table class="w-100 p-0 m-0">
			<tr>
				<td class="text-left pr-1"><b-icon icon="search" style="color:#93a1a7"></b-icon></td>
				<td><input type="text" v-model="query" class="w-100" :placeholder="placeholder" @focus="focus=true" @blur="focus=false" @change="onInput()"></td>
			</tr>
		</table>
	</div>`,
	data() {
		return {
			focus: false,
			query: ''
		}
	},
	methods: {
		onInput() {
			this.$emit('value', this.query);
		}
	}
});

Vue.component('cms-support-admin-panel', {
	template: `<div>
	<table style="max-width:95px !important" @click="handleSupportCenter">
		<tr>
			<td style="width:40px;text-align:right">
				<cms-support-admin-panel-help-icon></cms-support-admin-panel-help-icon>
			</td>
		</tr>
	</table>
</div>`,
	methods: {
		handleSupportCenter() {
			eventBus.$emit('cms-support-center-open');
		}
	}
});

Vue.component('cms-support-admin-panel-help-icon', {
	template: `<svg viewBox="0 0 12.7 12.7"><defs/><g transform="translate(0,-284.29998)"><circle cx="6.3500376" cy="290.64993" id="path4504" r="3.8805983" style="opacity:1;vector-effect:none;fill:none;fill-opacity:0.58506224;stroke:#fff;stroke-width:0.70555556;stroke-linecap:butt;stroke-linejoin:miter;stroke-miterlimit:4;stroke-dasharray:none;stroke-dashoffset:0;stroke-opacity:1"/><path d="m 5.2916669,289.94443 c 0,-0.70557 0.3527779,-1.05835 1.0583334,-1.05835 0.7055555,0 1.0583337,0.35278 1.0583334,1.05835 3e-7,0.70555 -1.0583334,0 -1.0583334,1.41109" id="path4506" style="fill:none;fill-rule:evenodd;stroke:#fff;stroke-width:0.705px;stroke-linecap:butt;stroke-linejoin:miter;stroke-opacity:1"/><rect height="0.70555556" style="opacity:1;vector-effect:none;fill:#fff;fill-opacity:1;stroke:none;stroke-width:0.70555556;stroke-linecap:butt;stroke-linejoin:miter;stroke-miterlimit:4;stroke-dasharray:none;stroke-dashoffset:0;stroke-opacity:1" width="0.70555556" x="5.9972234" y="292.06107"/></g></svg>`
});

Vue.component('cms-support-sidebar', {
	template: `<div class="cms-support-sidebar">
	<b-sidebar id="cms-support-center" title="Support center" backdrop-variant="dark" backdrop right shadow>
		<div class="px-3 py-2">
			<p>Welcome to the Technical Support Center!</p>
			<p>
				This section of the CMS will be used in the future to display help for the page you are currently on.
				You will also be able to create a support ticket here for technical support.
			</p>
		</div>
	</b-sidebar>
</div>`,
	mounted() {
		eventBus.$on('cms-support-center-open', () => {
			this.$root.$emit('bv::toggle::collapse', 'cms-support-center');
		});
	}
});

Vue.component('cms-default', {
	props: ['card', 'title', 'subtitle', 'buttons', 'breadcrumb', 'contextMenu'],
	template: `<div>
		<div v-if="breadcrumb" class="mb-2">
			<breadcrumb :items="breadcrumb"></breadcrumb>
		</div>
		<div v-if="title || subtitle || (buttons && buttons.length)" class="d-flex justify-content-between align-items-center flex-wrap">
			<div class="mb-2">
				<h1 v-if="title || subtitle">
					{{ title }}
					<small v-if="subtitle" class="text-muted">{{ subtitle }}</small>
				</h1>
			</div>
			<div class="mb-2">
				<cms-buttons :buttons="buttons" :context-menu="contextMenu"></cms-buttons>
			</div>
		</div>
		<b-card v-if="card">
			<slot></slot>
		</b-card>
		<template v-else>
			<slot></slot>
		</template>
	</div>`
});

Vue.component('cms-detail', {
	props: ['title', 'subtitle', 'breadcrumb', 'buttons', 'linkBack', 'contextMenu', 'smartComponent', 'smartComponentParams'],
	template: `<div>
		<div v-if="breadcrumb" class="mb-2">
			<breadcrumb :items="breadcrumb"></breadcrumb>
		</div>
		<div class="d-flex justify-content-between align-items-center flex-wrap">
			<div class="mb-2">
				<h1>
					{{ title }}
					<small class="text-muted">{{ subtitle }}</small>
				</h1>
			</div>
			<div class="mb-2">
				<cms-buttons :buttons="buttons" :link-back="linkBack" :context-menu="contextMenu"></cms-buttons>
				<component :is="smartComponent" v-bind="smartComponentParams"></component>
			</div>
		</div>
		<slot></slot>
	</div>`,
});

Vue.component('cms-buttons', {
	props: ['buttons', 'linkBack', 'contextMenu'],
	template: `<div>
		<template v-if="linkBack">
			<b-btn variant="white" class="mb-2 mb-lg-0" :href="linkBack">Back</b-btn>
		</template>
		<template v-for="button in buttons">
			<b-btn :variant="button.variant ? button.variant : 'secondary'" href="#" class="mr-1" @click.prevent="handleMenu(button)">
				<template v-if="!loading.includes(button.label)">
					<span v-if="button.icon" class="mr-2">
						<i v-if="button.icon" :class="['fa', button.icon]"></i>
					</span>
					{{ button.label }}
				</template>
				<b-spinner small v-else></b-spinner>
			</b-btn>
		</template>
		<b-dropdown v-if="contextMenu" id="context-dropdown" class="mb-2 mb-lg-0">
			<template v-for="contextMenuItem in contextMenu">
				<template v-if="contextMenuItem.action === 'divider'">
					<b-dropdown-divider></b-dropdown-divider>
				</template>
				<template v-else>
					<b-dropdown-item href="#" @click.prevent="handleMenu(contextMenuItem)" :active="contextMenuItem.active" :disabled="contextMenuItem.disabled">
						{{ contextMenuItem.name }}
					</b-dropdown-item>
				</template>
			</template>
		</b-dropdown>
	</div>`,
	data() {
		return {
			loading: []
		}
	},
	methods: {
		handleMenu(item) {
			switch (item.action) {
				case 'link':
					location.href = item.target;
					break;
				case 'linkTarget':
					window.open(item.target, '_blank');
					break;
				case 'linkTab':
					window.open(item.target);
					break;
				case 'modal':
					this.$bvModal.show(item.target);
					break;
				case 'method':
					let target = item.target().finally(() => this.loading = this.loading.filter(i => i !== item.label));
					if (target instanceof Promise) {
						this.loading.push(item.label);
					}
					break;
			}
		}
	}
});

Vue.component('cms-quick-edit', {
	props: {
		entity: {
			type: String,
			required: true
		},
		property: {
			type: String,
			required: true
		},
		id: {
			type: [String, Number],
			required: true
		},
		value: {
			required: true
		},
		type: {
			type: String,
			required: false
		},
		options: {
			required: false,
			default: function () {
				return {};
			}
		},
		truncate: {
			type: Number,
			required: false
		},
		confirm: {
			type: [String, Boolean],
			required: false,
			default: function () {
				return false;
			}
		},
		endpointUri: {
			type: [String, null],
			required: false,
			default: function () {
				return null;
			}
		}
	},
	template: `<div>
	<template v-if="loading">
		<b-spinner small></b-spinner>
	</template>
	<div v-else>
		<template v-if="type === 'bool'">
			<component is="cms-quick-edit-bool" :value="newValue" :element-key="key"></component>
		</template>
		<template v-else>
			<template v-if="type === 'datetime'">
				<component is="cms-quick-edit-datetime" :value="newValue" :element-key="key"></component>
			</template>
			<template v-else>
				<template v-if="editable === false && isUrl === true">
					<a :href="newValue" target="_blank" ref="nofollow noreferrer noopener">
						<b-icon icon="link45deg"></b-icon>
					</a>
				</template>
				<template v-if="editable">
					<component
						:is="'cms-quick-edit-' + (type ? type : 'text')"
						:value="newValue"
						:element-key="key"
						:options="options"></component>
				</template>
				<div v-else @click="startEditable()" style="border-bottom:1px dotted #000;cursor:pointer;min-width:2.5em !important;display:inline-block">
					<template v-if="newValue === '' || newValue === null">&nbsp;</template>
					<template v-else>
						<template v-if="truncate">
							<span :style="'max-width:' + truncate + 'px;white-space:nowrap;overflow:hidden;display:inline-block;text-overflow:ellipsis'">{{ newValue }}</span>
						</template>
						<template v-else>
							{{ newValue }}
						</template>
					</template>
				</div>
			</template>
		</template>
	</div>
</div>`,
	mounted() {
		this.originalValue = this.value;
		this.newValue = this.value;
		this.key = `quickEdit-${this.entity}-${this.id}-${this.property}-${Date.now()}_${Math.random() * 50000}`;
		eventBus.$on('cms-quick-edit-open', (key) => {
			if (key !== this.key) {
				this.editable = false;
			}
		});
		eventBus.$on('cms-quick-edit-save', (key, value) => {
			if (key !== this.key) return;
			if (this.timeout) clearTimeout(this.timeout);
			this.timeout = setTimeout(this.processSaveValue, 300, value);
		});
	},
	data() {
		return {
			editable: false,
			loading: false,
			key: '',
			originalValue: '',
			newValue: '',
			timeout: null
		}
	},
	watch: {
		value: function (newVal) {
			this.syncValue(newVal);
		}
	},
	methods: {
		startEditable() {
			eventBus.$emit('cms-quick-edit-open', this.key);
			this.editable = true;
		},
		syncValue(newVal) {
			this.originalValue = newVal;
			this.newValue = newVal;
			this.editable = false;
		},
		processSaveValue(value) {
			if (value === this.originalValue) {
				this.editable = false;
				this.loading = false;
				return;
			}
			if (this.confirm !== false) {
				if (
					(this.confirm === true && !confirm('Do you really want change ' + this.property + '?'))
					|| (this.confirm !== true && !confirm(this.confirm))
				) {
					this.loading = true;
					this.syncValue(this.originalValue);
					this.$nextTick(() => {
						this.loading = false;
					});
					return;
				}
			}
			if (this.type === 'bool') {
				value = value === true || value === 'true';
			}
			this.newValue = value;
			this.loading = true;
			this.$emit('click');
			axiosApi.interceptors.response.use((ok) => {
				return Promise.resolve(ok);
			}, (error) => {
				this.loading = false;
				return Promise.reject(error);
			});
			axiosApi.get((this.endpointUri ? this.endpointUri : 'quick-edit') + '?' + httpBuildQuery({
				entity: this.entity,
				property: this.property,
				id: this.id,
				value: value,
				type: this.type ? this.type : 'text'
			})).then(() => {
				this.editable = false;
				this.loading = false;
				this.originalValue = value;
				this.$emit('input', value);
				this.$emit('changed');
			});
		}
	},
	computed: {
		isUrl: function() {
			if (typeof this.newValue === 'string' || this.newValue instanceof String) {
				let pattern = new RegExp('^(https?:\\/\\/)'+ // protocol
					'((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|'+ // domain name
					'((\\d{1,3}\\.){3}\\d{1,3}))'+ // OR ip (v4) address
					'(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*'+ // port and path
					'(\\?[;&a-z\\d%_.~+=-]*)?'+ // query string
					'(\\#[-a-z\\d_]*)?$','i'); // fragment locator
				return !!pattern.test(this.newValue);
			}

			return false;
		}
	}
});

Vue.component('cms-quick-edit-text', {
	props: ['value', 'elementKey'],
	template: `<b-input-group>
		<b-form-input v-model="newValue" :id="elementKey" size="sm" @keyup.enter.native="save()" @blur="save()"></b-form-input>
		<b-input-group-append>
			<b-button size="sm" type="submit" variant="success" @click="save()">Save</b-button>
		</b-input-group-append>
	</b-input-group>`,
	data() {
		return {
			newValue: ''
		}
	},
	mounted() {
		this.newValue = this.value;
		this.$nextTick(() => {
			document.getElementById(this.elementKey).focus();
		});
	},
	methods: {
		save() {
			eventBus.$emit('cms-quick-edit-save', this.elementKey, this.newValue);
		}
	}
});

Vue.component('cms-quick-edit-bool', {
	props: ['value', 'elementKey'],
	template: `<b-form-checkbox v-model="newValue" :id="elementKey" switch @change="save()"></b-form-checkbox>`,
	data() {
		return {
			newValue: false
		}
	},
	mounted() {
		this.$nextTick(() => {
			this.newValue = this.value;
		});
	},
	watch: {
		value: function() {
			this.newValue = this.value === true || this.value === 'true';
		}
	},
	methods: {
		save() {
			this.$nextTick(() => {
				eventBus.$emit('cms-quick-edit-save', this.elementKey, this.newValue ? 'true' : 'false');
			});
		}
	}
});

Vue.component('cms-quick-edit-select', {
	props: ['value', 'elementKey', 'options'],
	template: `<b-form-select v-model="newValue" :id="elementKey" :options="options" size="sm" style="min-width:64px" @change="save()"></b-form-select>`,
	data() {
		return {
			newValue: null
		}
	},
	mounted() {
		this.$nextTick(() => {
			this.newValue = this.value;
		});
	},
	methods: {
		save() {
			this.$nextTick(() => {
				eventBus.$emit('cms-quick-edit-save', this.elementKey, this.newValue);
			});
		}
	}
});

Vue.component('cms-quick-edit-datetime', {
	props: ['value', 'elementKey'],
	template: `<b-form-datepicker v-model="newValue" :id="elementKey" size="sm" :startWeekday="1" :reset-button="true" :today-button="true" :date-format-options="{ 'year': 'numeric', 'month': 'short', 'day': 'numeric', 'weekday': 'short' }" style="font-size:10pt" @input="save()"></b-form-datepicker>`,
	data() {
		return {
			newValue: ''
		}
	},
	mounted() {
		this.$nextTick(() => {
			this.newValue = this.value;
		});
	},
	watch: {
		value: function (newVal) {
			this.newValue = newVal;
		}
	},
	methods: {
		save() {
			this.$nextTick(() => {
				eventBus.$emit('cms-quick-edit-save', this.elementKey, this.newValue);
			});
		}
	}
});

Vue.component('support-chat', {
	template: `<div v-if="show" id="cms-support">
	<div>
		Support is coming soon.
	</div>
</div>`,
	data() {
		return {
			show: false,
		}
	},
	mounted() {
		eventBus.$on('open-support', () => {
			this.show = true;
			this.sync();
		});
	},
	methods: {
		sync() {
		}
	}
});

Vue.prototype.link = link;

function link(route, params = {}) {
	let [plugin, view] = `${route}:`.split(':');

	if (plugin === '') {
		plugin = 'Homepage';
	}
	if (view === '') {
		view = 'default';
	}

	let path = basePath + '/admin/';
	let re = /([A-Z][a-z0-9]*)|(^[a-z]+)/g;
	plugin = plugin.match(re).map(i => i.toLowerCase()).join('-').trim();
	view = view.match(re).map(i => i.toLowerCase()).join('-').trim();

	if (plugin === 'homepage') {
		if (view !== 'default') {
			path += 'homepage/' + view;
		}
	} else {
		path += `${plugin}${view !== 'default' ? '/' + view : ''}`;
	}

	let keys = Object.entries(params);
	if (keys.length > 0) {
		path += `?${httpBuildQuery(params)}`;
	}

	return path;
}

// source: https://github.com/vladzadvorny/http-build-query/blob/master/index.js#L2
function isNumeric(n) {
	return !isNaN(parseFloat(n)) && isFinite(n);
}

function cleanArray(actual) {
	let newArray = [];
	for (let i = 0; i < actual.length; i++) {
		if (actual[i]) {
			newArray.push(actual[i]);
		}
	}
	return newArray;
}

function esc(param) {
	return encodeURIComponent(param)
		.replace(/[!'()*]/g, escape)
		.replace(/%20/g, '+');
}

function httpBuildQuery(queryData, numericPrefix, argSeparator, tempKey) {
	numericPrefix = numericPrefix || null;
	argSeparator = argSeparator || '&';
	tempKey = tempKey || null;
	if (!queryData) {
		return '';
	}

	let query = Object.keys(queryData).map(function (k) {
		let res;
		let key = k;
		if (tempKey) key = tempKey + '[' + key + ']';
		if (typeof queryData[k] === 'object' && queryData[k] !== null) {
			res = httpBuildQuery(queryData[k], null, argSeparator, key);
		} else {
			if (numericPrefix) {
				key = isNumeric(key) ? numericPrefix + Number(key) : key;
			}

			let val = queryData[k];
			val = val === true ? '1' : val;
			val = val === false ? '0' : val;
			val = val === 0 ? '0' : val;
			val = val || '';

			res = esc(key) + '=' + esc(val);
		}
		return res;
	});

	return cleanArray(query)
		.join(argSeparator)
		.replace(/[!'()*]/g, '');
}
