# Variables and flags
MAKEFLAGS += --silent
DOCKER_COMPOSE = docker-compose --file docker-compose.yml
WORK_DIRECTORY = app

# Right and OS management
UID := $(shell id -u)
GID := $(shell id -g)
OS := $(shell uname)

# Do not remove - it filters empty arguments from command line
%:
	@:

# Help
help:
	@echo "Usage:"
	@echo "  make <COMMAND>"
	@echo ""

	@echo "  Docker commands:"
	@echo "  ----------------------------------------------------------------------------------------"

	@echo "  start					Start all docker container"
	@echo "  stop					Stop all docker container"
	@echo "  restart				Restart all docker container"
	@echo "  down					Destroy all docker container"
	@echo "  exec					Connect directly to docker container"

	@echo "  ----------------------------------------------------------------------------------------"
	@echo ""

	@echo "  Test commands:"
	@echo "  ----------------------------------------------------------------------------------------"
	@echo "  phpunit, pu				Run php unit tests"
	@echo "  ----------------------------------------------------------------------------------------"
	@echo ""

# Docker
start:
	@echo "--- Starting docker container ---"
	rm -rf dump.rdb
	${DOCKER_COMPOSE} up --build -d
	${DOCKER_COMPOSE} exec app bash -c "composer install"
	@echo "--- Started docker container ---"

stop:
	@echo "--- Stopping docker container ---"
	${DOCKER_COMPOSE} stop -t1
	@echo "--- Stopped docker container ---"

restart: stop start

down:
	@echo "--- Destroying docker container ---"
	${DOCKER_COMPOSE} down -v
	@echo "--- Finished destroying docker container ---"

exec:
	@echo "--- Connecting to ${WORK_DIRECTORY} container ---"
	${DOCKER_COMPOSE} exec ${WORK_DIRECTORY} bash

# Tests
phpunit pu:
	@echo "--- Running php unit tests ---"
	${DOCKER_COMPOSE} exec app ./vendor/bin/phpunit
	@echo "--- Finished running php unit tests ---"
