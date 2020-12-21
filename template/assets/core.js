Vue.component('cms-menu', {
	props: ['structure', 'activeKey', 'dashboardLink', 'isDashboard', 'debugMode'],
	template: `<div>
		<div :class="{'cms-menu-item': true, 'cms-menu-item-selected': isDashboard}" @click="window.location.href=dashboardLink">
			<b-icon icon="compass" class="mr-2"></b-icon>Dashboard
		</div>
		<template v-for="item in structure">
			<cms-menu-item :item-key="item.key" :title="item.title" :link="item.link" :icon="item.icon" :child="item.child" :priority="item.priority" :active-key="activeKey" :debug-mode="debugMode"></cms-menu-item>
		</template>
	</div>`
});

Vue.component('cms-menu-item', {
	props: ['itemKey', 'title', 'link', 'icon', 'child', 'priority', 'activeKey', 'debugMode'],
	template: `<div :class="{'cms-menu-item': true, 'cms-menu-item-selected': itemKey === activeKey}" @click="processLink(link)">
	<template v-if="debugMode">({{ priority }})</template>
	<b-icon :icon="icon ? icon : 'compass'" class="mr-2"></b-icon>{{ title }}
	<div class="cms-menu-item" v-if="child.length > 0">
		<template v-for="item in child">
			<cms-menu-item :item-key="item.key" :title="item.title" :link="item.link" :icon="item.icon" :child="item.child" :priority="item.priority" :debug-mode="debugMode"></cms-menu-item>
		</template>
	</div>
</div>`,
	methods: {
		processLink(link) {
			window.location.href = basePath + '/' + link;
		}
	}
});

Vue.component('cms-footer', {
	props: ['year'],
	template: `<footer role="contentinfo">
	<div class="cms-footer">
		<div class="container" role="contentinfo">
			<div class="row">
				<div class="col-sm-7">
					<p>
						<a href="https://baraja.cz/cms" target="_blank" style="text-decoration:underline">Baraja CMS</a>
						©&nbsp;2009-{{ year }}
						|&nbsp;<a href="https://help.baraja.cz" target="_blank">Help</a>
						|&nbsp;<a href="https://baraja.cz/kontakt" target="_blank">Support</a>
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

Vue.component('cms-default', {
	props: ['card', 'title', 'subtitle', 'buttons', 'breadcrumb', 'contextMenu'],
	template: `<div>
		<div v-if="breadcrumb" class="mb-2">
			<breadcrumb :items="breadcrumb"></breadcrumb>
		</div>
		<div class="d-flex justify-content-between align-items-center flex-wrap">
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
			<b-btn variant="white" class="mb-2 mb-lg-0">Back</b-btn>
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
