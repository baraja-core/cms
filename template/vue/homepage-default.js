Vue.component('homepage-default', {
	template: `<cms-default title="Welcome back!">
	<b-card>
		<b-form @submit="sendTopic">
			<b-form-textarea v-model="topic.message" rows="6" placeholder="What\'s on your mind?"></b-form-textarea>
			<b-button variant="primary" type="submit" class="mt-3">
				<template v-if="topic.isLoading"><b-spinner small></b-spinner></template>
				<template v-else>Post</template>
			</b-button>
		</b-form>
	</b-card>
	<div v-if="feed === null" class="text-center my-5">
		<b-spinner></b-spinner>
	</div>
	<template v-else>
		<template v-for="topic in feed">
			<b-card class="mt-3" bg-variant="topic.pinned ? 'dark' : 'default'">
				{{ topic.message }}
				<div class="text-right text-secondary">
					{{ topic.user.username }} |&nbsp;{{ topic.showSince }}
				</div>
			</b-card>
		</template>
	</template>
</cms-default>`,
	data() {
		return {
			feed: null,
			topic: {
				message: '',
				isLoading: false
			}
		}
	},
	mounted() {
		this.sync();
		setInterval(this.sync, 15000);
	},
	methods: {
		sync() {
			this.$nextTick(() => {
				axiosApi.get('cms-dashboard/feed').then(req => {
					this.feed = req.data.feed;
				});
			});
		},
		sendTopic(event) {
			event.preventDefault();
			this.topic.isLoading = true;
			axiosApi.post('cms-dashboard/post-topic', {
				message: this.topic.message
			}).then((req) => {
				this.topic.message = '';
				this.sync();
			}).finally(() => this.topic.isLoading = false)
		}
	}
});
