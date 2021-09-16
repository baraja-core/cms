Vue.component('cms-editor', {
	model: {
		prop: 'value',
	},
	props: ['value', 'rows', 'label'],
	template: `<div>
	<label :for="labelId">{{ label }}</label>
	<b-card no-body>
		<b-tabs card>
			<b-tab title="Editor" lazy active class="p-0">
				<textarea v-model="content" :id="labelId" class="form-control" :rows="rows ? rows : 5" style="border:0 !important"></textarea>
			</b-tab>
			<b-tab title="Preview" lazy @click="renderPreview" class="p-0">
				<div v-if="!content" class="p-3"><i>Empty preview.</i></div>
				<div v-else class="p-3">
					<div v-if="isLoading" class="text-center my-5"><b-spinner></b-spinner></div>
					<div v-else v-html="preview"></div>
				</div>
			</b-tab>
		</b-tabs>
	</b-card>
	<div class="card" style="border-bottom:0 !important">
		<div class="card-header px-2 py-1">
			<a href="https://brj.cz/markdown" target="_blank">
				<svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" version="1.1">
				<path fill-rule="evenodd" d="M14.85 3H1.15C.52 3 0 3.52 0 4.15v7.69C0 12.48.52 13 1.15 13h13.69c.64 0 1.15-.52 1.15-1.15v-7.7C16 3.52 15.48 3 14.85 3zM9 11H7V8L5.5 9.92 4 8v3H2V5h2l1.5 2L7 5h2v6zm2.99.5L9.5 8H11V5h2v3h1.5l-2.51 3.5z"></path>
				</svg>
				Styling with Markdown is supported
			</a>
		</div>
	</div>
</div>`,
	data() {
		return {
			hash: '',
			preview: '',
			isLoading: false
		};
	},
	mounted() {
		this.hash = this.computeHash(this.label);
	},
	computed: {
		labelId: function () {
			return 'cme-editor--' + this.hash;
		},
		content: {
			get() {
				return this.value;
			},
			set(val) {
				this.$emit('input', val);
			}
		}
	},
	methods: {
		computeHash(haystack) {
			let hash = 0, i, chr;
			if (haystack.length === 0) return hash;
			for (i = 0; i < haystack.length; i++) {
				chr = haystack.charCodeAt(i);
				hash = ((hash << 5) - hash) + chr;
				hash |= 0;
			}
			return hash;
		},
		renderPreview() {
			if (!this.content) {
				return;
			}
			this.isLoading = true;
			fetch(baseApiPath + '/cms/render-editor-preview', {
				method: 'POST',
				body: JSON.stringify({
					haystack: this.content
				})
			})
				.then(data => data.json())
				.then(data => {
					this.isLoading = false;
					this.preview = data.html;
				});
		}
	}
});
