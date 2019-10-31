function post(url, parameters, app, callback) {
	axios.post(url, parameters, {
			headers: {'Content-Type': 'application/x-www-form-urlencoded'}
		}
	).then(response => {
		if ('redirectUrl' in response.data) {
			window.location.replace(response.data.redirectUrl);
		}

		if (app !== undefined && callback !== undefined) {
			callback(app, response.data);
		}
	});
}

Vue.component('ui-header', {
	data: function () {
		return {
			query: '',
			autocomplete: false,
			autocompleteResults: {},
			autocompleteCache: {}
		}
	},
	template:
		`<div class="ui-header">
			<b-container fluid>
				<b-row>
					<b-col cols="1" style="min-width:10em !important">
						<b-container fluid>
							<b-row>
								<b-col class="px-0 pt-2" cols="3">
									<div class="ui-burger"></div>
									<div class="ui-burger"></div>
									<div class="ui-burger"></div>
								</b-col>
								<b-col class="px-0" style="min-width:6em">
									<a href="${basePath}/admin">
										<img src="${basePath}/admin/api/cms/logo" style="width:6em;height:2.5em">
									</a>
								</b-col>
							</b-row>
						</b-container>
					</b-col>
					<b-col class="py-2">
						<input type="search" class="ui-header-search" v-model="query" @input="search()">
						<div v-if="autocomplete" class="w-100 ui-header-search-autocomplete">
							<b-card>
								<div v-for="result in autocompleteResults">
									{{ result.title }}
								</div>
							</b-card>
						</div>
					</b-col>
					<b-col cols="2">User</b-col>
				</b-row>
			</b-container>
		</div>`,
	methods: {
		search() {
			this.autocomplete = this.query !== '';
			if (this.query in this.autocompleteCache) {
				this.autocompleteResults = this.autocompleteCache[this.query];
			} else {
				post(baseApiPath + '/cms/autocomplete', {
					query: this.query
				}, this, function (component, response) {
					component.autocompleteResults = response.results;
					component.autocompleteCache[response.query] = response.results;
				});
			}
		}
	}
});

Vue.component('ui-left-menu', {
	props: ['items'],
	template: `<b-container fluid>
		<b-row>
			<b-col>
				<div>
					Menu
				</div>
				<div v-for="item in items">
					<div @click="window.location.replace(item.link);">{{ item.label }}</b-link>
				</div>
			</b-col>
		</b-row>
	</b-container>`
});

Vue.component('ui-footer', {
	template: `<b-container fluid class="ui-footer py-3">
		<b-row>
			<b-col>
				<b-container>
					<b-row>
						<b-col>
							Footer
						</b-col>
					</b-row>
				</b-container>
			</b-col>
		</b-row>
	</b-container>`
});